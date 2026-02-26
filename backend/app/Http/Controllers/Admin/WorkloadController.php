<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\AiJobDispatch;
use App\Models\ComfyUiWorker;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WorkloadController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'string|nullable|in:24h,7d,30d',
            'stage' => 'string|nullable|in:staging,production',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $period = $request->input('period', '24h');
        $stage = $request->input('stage', 'production');
        $periodStart = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHours(24),
        };

        // Get all workflows
        $workflows = Workflow::query()->orderBy('name')->get();

        // Aggregate dispatch stats per workflow
        // Current queue/processing counts (not period-filtered)
        $currentStats = AiJobDispatch::query()
            ->select(
                'workflow_id',
                DB::raw("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued"),
                DB::raw("SUM(CASE WHEN status = 'leased' THEN 1 ELSE 0 END) as processing"),
                DB::raw("SUM(CASE WHEN status IN ('queued', 'leased') THEN COALESCE(work_units, 1) ELSE 0 END) as queue_units"),
            )
            ->whereIn('status', ['queued', 'leased'])
            ->where('stage', $stage)
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id');

        // Period-filtered completed/failed stats
        $periodStats = AiJobDispatch::query()
            ->select(
                'workflow_id',
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw("AVG(CASE WHEN status = 'completed' AND duration_seconds IS NOT NULL THEN duration_seconds ELSE NULL END) as avg_duration_seconds"),
                DB::raw("SUM(CASE WHEN status = 'completed' AND duration_seconds IS NOT NULL THEN duration_seconds ELSE 0 END) as total_duration_seconds"),
            )
            ->whereIn('status', ['completed', 'failed'])
            ->where('stage', $stage)
            ->where('updated_at', '>=', $periodStart)
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id');

        $dispatchSamples = AiJobDispatch::query()
            ->select('workflow_id', 'queue_wait_seconds', 'processing_seconds', 'duration_seconds', 'work_units')
            ->where('status', 'completed')
            ->where('stage', $stage)
            ->where('updated_at', '>=', $periodStart)
            ->get()
            ->groupBy('workflow_id');

        $activeWorkerStats = DB::connection('central')
            ->table('comfy_ui_workers as w')
            ->join('worker_workflows as ww', 'w.id', '=', 'ww.worker_id')
            ->where('w.is_approved', true)
            ->where('w.stage', $stage)
            ->where('w.last_seen_at', '>=', now()->subMinutes(5))
            ->selectRaw('ww.workflow_id, COUNT(DISTINCT w.id) as active_workers')
            ->groupBy('ww.workflow_id')
            ->get()
            ->keyBy('workflow_id');

        // Get worker assignments per workflow
        $workflowWorkerMap = DB::table('worker_workflows as ww')
            ->join('comfy_ui_workers as w', 'w.id', '=', 'ww.worker_id')
            ->where('w.stage', $stage)
            ->select('ww.workflow_id', 'ww.worker_id')
            ->get()
            ->groupBy('workflow_id')
            ->map(fn ($rows) => $rows->pluck('worker_id')->toArray());

        $workflowsData = $workflows->map(function ($workflow) use ($currentStats, $periodStats, $workflowWorkerMap, $dispatchSamples, $activeWorkerStats) {
            $current = $currentStats->get($workflow->id);
            $period = $periodStats->get($workflow->id);
            $samples = $dispatchSamples->get($workflow->id, collect());
            $activeWorkers = (int) ($activeWorkerStats->get($workflow->id)->active_workers ?? 0);

            $queueWaits = $samples
                ->pluck('queue_wait_seconds')
                ->filter(fn ($value) => $value !== null)
                ->map(fn ($value) => (float) $value)
                ->values()
                ->all();
            $p95QueueWait = $this->percentile($queueWaits, 0.95);

            $processingRatios = $samples
                ->map(function ($row) {
                    $units = (float) ($row->work_units ?? 0);
                    $seconds = (float) ($row->processing_seconds ?? $row->duration_seconds ?? 0);
                    if ($units <= 0 || $seconds <= 0) {
                        return null;
                    }
                    return $seconds / $units;
                })
                ->filter()
                ->values()
                ->all();
            $p95ProcessingPerUnit = $this->percentile($processingRatios, 0.95);

            $queueUnits = (float) ($current?->queue_units ?? 0);
            $estimatedWaitSeconds = null;
            if ($p95ProcessingPerUnit !== null) {
                $estimatedWaitSeconds = $activeWorkers > 0
                    ? ($queueUnits / $activeWorkers) * $p95ProcessingPerUnit
                    : $queueUnits * $p95ProcessingPerUnit;
            }

            $workloadKind = $workflow->workload_kind;
            if (!in_array($workloadKind, ['image', 'video'], true)) {
                $mimeType = strtolower((string) ($workflow->output_mime_type ?? ''));
                $workloadKind = str_starts_with($mimeType, 'video/') ? 'video' : 'image';
            }

            $sloPressure = null;
            if ($workloadKind === 'video') {
                $sloVideo = (float) ($workflow->slo_video_seconds_per_processing_second_p95 ?? 0);
                if ($sloVideo > 0 && $p95ProcessingPerUnit !== null) {
                    $sloPressure = round($p95ProcessingPerUnit * $sloVideo, 4);
                }
            } else {
                $sloWait = (float) ($workflow->slo_p95_wait_seconds ?? 0);
                if ($sloWait > 0 && $estimatedWaitSeconds !== null) {
                    $sloPressure = round($estimatedWaitSeconds / $sloWait, 4);
                }
            }

            $recommendedWorkers = null;
            if ($workloadKind === 'image') {
                $sloWait = (float) ($workflow->slo_p95_wait_seconds ?? 0);
                if ($sloWait > 0 && $p95ProcessingPerUnit !== null) {
                    $recommendedWorkers = (int) ceil(($queueUnits * $p95ProcessingPerUnit) / $sloWait);
                }
            }

            return [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'slug' => $workflow->slug,
                'is_active' => (bool) $workflow->is_active,
                'stats' => [
                    'queued' => (int) ($current?->queued ?? 0),
                    'processing' => (int) ($current?->processing ?? 0),
                    'queue_units' => $queueUnits,
                    'active_workers' => $activeWorkers,
                    'completed' => (int) ($period?->completed ?? 0),
                    'failed' => (int) ($period?->failed ?? 0),
                    'avg_duration_seconds' => $period?->avg_duration_seconds !== null ? (int) round($period->avg_duration_seconds) : null,
                    'total_duration_seconds' => $period?->total_duration_seconds ? (int) $period->total_duration_seconds : null,
                    'p95_queue_wait_seconds' => $p95QueueWait !== null ? (int) round($p95QueueWait) : null,
                    'processing_seconds_per_unit_p95' => $p95ProcessingPerUnit !== null ? round($p95ProcessingPerUnit, 4) : null,
                    'estimated_wait_seconds_p95' => $estimatedWaitSeconds !== null ? round($estimatedWaitSeconds, 2) : null,
                    'slo_pressure' => $sloPressure,
                    'slo_p95_wait_seconds' => $workflow->slo_p95_wait_seconds,
                    'slo_video_seconds_per_processing_second_p95' => $workflow->slo_video_seconds_per_processing_second_p95,
                    'workload_kind' => $workloadKind,
                    'work_units_property_key' => $workflow->work_units_property_key,
                    'recommended_workers' => $recommendedWorkers,
                ],
                'worker_ids' => $workflowWorkerMap->get($workflow->id, []),
            ];
        });

        // Get all workers with relevant fields
        $workers = ComfyUiWorker::query()
            ->select('id', 'worker_id', 'display_name', 'is_approved', 'is_draining', 'current_load', 'max_concurrency', 'last_seen_at')
            ->where('stage', $stage)
            ->orderBy('worker_id')
            ->get();

        return $this->sendResponse([
            'workflows' => $workflowsData->values(),
            'workers' => $workers,
        ], 'Workload data retrieved');
    }

    public function assignWorkers(Request $request, $id): JsonResponse
    {
        $workflow = Workflow::find($id);
        if (!$workflow) {
            return $this->sendError('Workflow not found');
        }

        $validator = Validator::make($request->all(), [
            'worker_ids' => 'present|array',
            'worker_ids.*' => 'integer|exists:comfy_ui_workers,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $workflow->workers()->sync($request->input('worker_ids', []));

        return $this->sendResponse([
            'workflow_id' => $workflow->id,
            'worker_ids' => $workflow->workers()->pluck('comfy_ui_workers.id')->toArray(),
        ], 'Workers assigned');
    }

    private function percentile(array $values, float $percentile): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $index = (int) floor(count($values) * $percentile);
        $index = min($index, count($values) - 1);
        return (float) $values[$index];
    }
}
