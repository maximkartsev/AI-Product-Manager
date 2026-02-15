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
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $period = $request->input('period', '24h');
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
            )
            ->whereIn('status', ['queued', 'leased'])
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
            ->where('updated_at', '>=', $periodStart)
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id');

        // Get worker assignments per workflow
        $workflowWorkerMap = DB::table('worker_workflows')
            ->select('workflow_id', 'worker_id')
            ->get()
            ->groupBy('workflow_id')
            ->map(fn ($rows) => $rows->pluck('worker_id')->toArray());

        $workflowsData = $workflows->map(function ($workflow) use ($currentStats, $periodStats, $workflowWorkerMap) {
            $current = $currentStats->get($workflow->id);
            $period = $periodStats->get($workflow->id);

            return [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'slug' => $workflow->slug,
                'is_active' => (bool) $workflow->is_active,
                'stats' => [
                    'queued' => (int) ($current?->queued ?? 0),
                    'processing' => (int) ($current?->processing ?? 0),
                    'completed' => (int) ($period?->completed ?? 0),
                    'failed' => (int) ($period?->failed ?? 0),
                    'avg_duration_seconds' => $period?->avg_duration_seconds !== null ? (int) round($period->avg_duration_seconds) : null,
                    'total_duration_seconds' => $period?->total_duration_seconds ? (int) $period->total_duration_seconds : null,
                ],
                'worker_ids' => $workflowWorkerMap->get($workflow->id, []),
            ];
        });

        // Get all workers with relevant fields
        $workers = ComfyUiWorker::query()
            ->select('id', 'worker_id', 'display_name', 'is_approved', 'is_draining', 'current_load', 'max_concurrency', 'last_seen_at')
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
}
