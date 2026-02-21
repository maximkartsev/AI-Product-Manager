<?php

use App\Models\AiJobDispatch;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorker;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\WorkerAuditLog;
use App\Models\Workflow;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\VideoCleanupService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('videos:cleanup-expired', function () {
    $count = app(VideoCleanupService::class)->cleanupExpiredVideos();
    $this->comment("Expired videos cleaned: {$count}");
})->purpose('Cleanup expired processed videos');

/*
|--------------------------------------------------------------------------
| Workers: Publish CloudWatch Metrics
|--------------------------------------------------------------------------
*/
Artisan::command('workers:publish-metrics', function () {
    $maxAttempts = (int) config('services.comfyui.max_attempts', 3);
    $namespace = 'ComfyUI/Workers';
    $stage = (string) config('app.env');
    if (!in_array($stage, ['staging', 'production'], true)) {
        $stage = 'staging';
    }
    $emitWorkflowMetrics = (bool) config('services.comfyui.emit_workflow_metrics', true);
    $emitFleetMetrics = (bool) config('services.comfyui.emit_fleet_metrics', true);

    // All active workflows
    $workflows = Workflow::query()->where('is_active', true)->get();

    $workflowToFleet = ComfyUiWorkflowFleet::query()
        ->where('stage', $stage)
        ->pluck('fleet_id', 'workflow_id');

    $fleets = ComfyUiGpuFleet::query()
        ->where('stage', $stage)
        ->get(['id', 'slug']);

    $fleetAggregates = [];
    foreach ($fleets as $fleet) {
        $fleetAggregates[$fleet->id] = [
            'slug' => $fleet->slug,
            'queueDepth' => 0,
            'availableCapacity' => 0,
            'activeWorkers' => 0,
            'durations' => [],
            'failed' => 0,
            'total' => 0,
            'leaseExpired' => 0,
            'spotInterruptions' => 0,
        ];
    }

    // Single GROUP BY query for queue stats
    $queueStats = AiJobDispatch::query()
        ->whereNotNull('workflow_id')
        ->whereIn('status', ['queued', 'leased'])
        ->where('attempts', '<', $maxAttempts)
        ->selectRaw('workflow_id,
            SUM(CASE WHEN status = \'queued\' THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN status = \'leased\' THEN 1 ELSE 0 END) as leased')
        ->groupBy('workflow_id')
        ->get()
        ->keyBy('workflow_id');

    // Worker stats per workflow
    $workerStats = DB::connection('central')
        ->table('comfy_ui_workers as w')
        ->join('worker_workflows as ww', 'w.id', '=', 'ww.worker_id')
        ->where('w.is_approved', true)
        ->where('w.last_seen_at', '>=', now()->subMinutes(5))
        ->selectRaw('ww.workflow_id,
            COUNT(DISTINCT w.id) as active_workers,
            SUM(w.max_concurrency - w.current_load) as available_capacity')
        ->groupBy('ww.workflow_id')
        ->get()
        ->keyBy('workflow_id');

    $fleetWorkerStats = collect();
    if ($emitFleetMetrics) {
        $workerFleetSub = DB::connection('central')
            ->table('comfy_ui_workers as w')
            ->join('worker_workflows as ww', 'w.id', '=', 'ww.worker_id')
            ->join('comfyui_workflow_fleets as wf', 'wf.workflow_id', '=', 'ww.workflow_id')
            ->where('wf.stage', $stage)
            ->where('w.is_approved', true)
            ->where('w.last_seen_at', '>=', now()->subMinutes(5))
            ->select('w.id as worker_id', 'wf.fleet_id', 'w.max_concurrency', 'w.current_load')
            ->distinct();

        $fleetWorkerStats = DB::connection('central')
            ->query()
            ->fromSub($workerFleetSub, 'x')
            ->selectRaw('fleet_id, COUNT(DISTINCT worker_id) as active_workers, SUM(max_concurrency - current_load) as available_capacity')
            ->groupBy('fleet_id')
            ->get()
            ->keyBy('fleet_id');
    }

    // Job processing P50 per workflow (completed in last 10 min)
    $durationStats = AiJobDispatch::query()
        ->whereNotNull('workflow_id')
        ->where('status', 'completed')
        ->whereNotNull('duration_seconds')
        ->where('updated_at', '>=', now()->subMinutes(10))
        ->selectRaw('workflow_id, duration_seconds')
        ->orderBy('workflow_id')
        ->orderBy('duration_seconds')
        ->get()
        ->groupBy('workflow_id');

    // Error rate per workflow (last 5 min)
    $errorStats = AiJobDispatch::query()
        ->whereNotNull('workflow_id')
        ->whereIn('status', ['completed', 'failed'])
        ->where('updated_at', '>=', now()->subMinutes(5))
        ->selectRaw('workflow_id,
            SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed,
            COUNT(*) as total')
        ->groupBy('workflow_id')
        ->get()
        ->keyBy('workflow_id');

    // Lease expired count per workflow (last 5 min)
    $leaseExpiredStats = AiJobDispatch::query()
        ->whereNotNull('workflow_id')
        ->where('status', 'leased')
        ->whereNotNull('lease_expires_at')
        ->where('lease_expires_at', '<=', now())
        ->selectRaw('workflow_id, COUNT(*) as expired')
        ->groupBy('workflow_id')
        ->get()
        ->keyBy('workflow_id');

    // Spot interruption count per workflow (last 5 min)
    $spotStats = WorkerAuditLog::query()
        ->where('event', 'requeued')
        ->where('created_at', '>=', now()->subMinutes(5))
        ->whereNotNull('dispatch_id')
        ->selectRaw('dispatch_id')
        ->get()
        ->pluck('dispatch_id');

    $spotByWorkflow = [];
    if ($spotStats->isNotEmpty()) {
        $spotByWorkflow = AiJobDispatch::query()
            ->whereIn('id', $spotStats)
            ->whereNotNull('workflow_id')
            ->selectRaw('workflow_id, COUNT(*) as requeued')
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id')
            ->toArray();
    }

    $metricData = [];

    foreach ($workflows as $workflow) {
        $wId = $workflow->id;
        $slug = $workflow->slug;

        $queued = (int) ($queueStats[$wId]->queued ?? 0);
        $leased = (int) ($queueStats[$wId]->leased ?? 0);
        $queueDepth = $queued + $leased;
        $activeWorkers = (int) ($workerStats[$wId]->active_workers ?? 0);
        $availableCapacity = (int) ($workerStats[$wId]->available_capacity ?? 0);
        $backlog = $activeWorkers > 0 ? round($queueDepth / $activeWorkers, 2) : $queueDepth;

        // P50 duration
        $p50 = 0;
        if (isset($durationStats[$wId]) && $durationStats[$wId]->count() > 0) {
            $durations = $durationStats[$wId]->pluck('duration_seconds')->sort()->values();
            $p50Index = (int) floor($durations->count() * 0.5);
            $p50 = $durations[$p50Index] ?? 0;
        }

        // Error rate
        $errorRate = 0;
        if (isset($errorStats[$wId]) && $errorStats[$wId]->total > 0) {
            $errorRate = round(($errorStats[$wId]->failed / $errorStats[$wId]->total) * 100, 2);
        }

        $leaseExpired = (int) ($leaseExpiredStats[$wId]->expired ?? 0);
        $spotInterruptions = (int) ($spotByWorkflow[$wId]['requeued'] ?? 0);

        if ($emitWorkflowMetrics) {
            $dimensions = [
                ['Name' => 'WorkflowSlug', 'Value' => $slug],
            ];

            $metricData[] = ['MetricName' => 'QueueDepth', 'Dimensions' => $dimensions, 'Value' => $queueDepth, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'BacklogPerInstance', 'Dimensions' => $dimensions, 'Value' => $backlog, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'ActiveWorkers', 'Dimensions' => $dimensions, 'Value' => $activeWorkers, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'AvailableCapacity', 'Dimensions' => $dimensions, 'Value' => $availableCapacity, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'JobProcessingP50', 'Dimensions' => $dimensions, 'Value' => $p50, 'Unit' => 'Seconds'];
            $metricData[] = ['MetricName' => 'ErrorRate', 'Dimensions' => $dimensions, 'Value' => $errorRate, 'Unit' => 'Percent'];
            $metricData[] = ['MetricName' => 'LeaseExpiredCount', 'Dimensions' => $dimensions, 'Value' => $leaseExpired, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'SpotInterruptionCount', 'Dimensions' => $dimensions, 'Value' => $spotInterruptions, 'Unit' => 'Count'];

            $this->info("  {$slug}: depth={$queueDepth} backlog={$backlog} workers={$activeWorkers} capacity={$availableCapacity} p50={$p50}s err={$errorRate}% expired={$leaseExpired} spot={$spotInterruptions}");
        }

        if ($emitFleetMetrics) {
            $fleetId = $workflowToFleet->get($wId);
            if ($fleetId && isset($fleetAggregates[$fleetId])) {
                $fleetAggregates[$fleetId]['queueDepth'] += $queueDepth;
                $fleetAggregates[$fleetId]['failed'] += (int) ($errorStats[$wId]->failed ?? 0);
                $fleetAggregates[$fleetId]['total'] += (int) ($errorStats[$wId]->total ?? 0);
                $fleetAggregates[$fleetId]['leaseExpired'] += $leaseExpired;
                $fleetAggregates[$fleetId]['spotInterruptions'] += $spotInterruptions;

                if (isset($durationStats[$wId]) && $durationStats[$wId]->count() > 0) {
                    $fleetAggregates[$fleetId]['durations'] = array_merge(
                        $fleetAggregates[$fleetId]['durations'],
                        $durationStats[$wId]->pluck('duration_seconds')->all()
                    );
                }
            }
        }
    }

    if ($emitFleetMetrics) {
        foreach ($fleetAggregates as $fleetId => $stats) {
            $fleetSlug = $stats['slug'];
            $fleetWorkers = $fleetWorkerStats[$fleetId] ?? null;
            $activeWorkers = (int) ($fleetWorkers->active_workers ?? 0);
            $availableCapacity = (int) ($fleetWorkers->available_capacity ?? 0);
            $queueDepth = (int) $stats['queueDepth'];
            $backlog = $activeWorkers > 0 ? round($queueDepth / $activeWorkers, 2) : $queueDepth;

            $p50 = 0;
            if (!empty($stats['durations'])) {
                sort($stats['durations']);
                $p50Index = (int) floor(count($stats['durations']) * 0.5);
                $p50 = $stats['durations'][$p50Index] ?? 0;
            }

            $errorRate = 0;
            if ($stats['total'] > 0) {
                $errorRate = round(($stats['failed'] / $stats['total']) * 100, 2);
            }

            $dimensions = [
                ['Name' => 'FleetSlug', 'Value' => $fleetSlug],
            ];

            $metricData[] = ['MetricName' => 'QueueDepth', 'Dimensions' => $dimensions, 'Value' => $queueDepth, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'BacklogPerInstance', 'Dimensions' => $dimensions, 'Value' => $backlog, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'ActiveWorkers', 'Dimensions' => $dimensions, 'Value' => $activeWorkers, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'AvailableCapacity', 'Dimensions' => $dimensions, 'Value' => $availableCapacity, 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'JobProcessingP50', 'Dimensions' => $dimensions, 'Value' => $p50, 'Unit' => 'Seconds'];
            $metricData[] = ['MetricName' => 'ErrorRate', 'Dimensions' => $dimensions, 'Value' => $errorRate, 'Unit' => 'Percent'];
            $metricData[] = ['MetricName' => 'LeaseExpiredCount', 'Dimensions' => $dimensions, 'Value' => (int) $stats['leaseExpired'], 'Unit' => 'Count'];
            $metricData[] = ['MetricName' => 'SpotInterruptionCount', 'Dimensions' => $dimensions, 'Value' => (int) $stats['spotInterruptions'], 'Unit' => 'Count'];

            $this->info("  fleet {$fleetSlug}: depth={$queueDepth} backlog={$backlog} workers={$activeWorkers} capacity={$availableCapacity} p50={$p50}s err={$errorRate}% expired={$stats['leaseExpired']} spot={$stats['spotInterruptions']}");
        }
    }

    if (empty($metricData)) {
        $this->comment('No metrics to publish.');
        return;
    }

    // Publish to CloudWatch in batches of 20 (API limit)
    try {
        $cw = new \Aws\CloudWatch\CloudWatchClient([
            'region' => config('services.ses.region', 'us-east-1'),
            'version' => 'latest',
        ]);

        foreach (array_chunk($metricData, 20) as $batch) {
            $cw->putMetricData([
                'Namespace' => $namespace,
                'MetricData' => $batch,
            ]);
        }

        $this->info('Published ' . count($metricData) . ' metrics to CloudWatch.');
    } catch (\Throwable $e) {
        $this->error('CloudWatch publish failed: ' . $e->getMessage());
    }
})->purpose('Publish worker metrics to CloudWatch (workflow + fleet)');

/*
|--------------------------------------------------------------------------
| Workers: Cleanup Stale Fleet Workers
|--------------------------------------------------------------------------
*/
Artisan::command('workers:cleanup-stale', function () {
    $staleHours = (int) config('services.comfyui.stale_worker_hours', 2);

    $staleWorkers = ComfyUiWorker::query()
        ->where('registration_source', 'fleet')
        ->where(function ($q) use ($staleHours) {
            $q->where('last_seen_at', '<', now()->subHours($staleHours))
              ->orWhereNull('last_seen_at');
        })
        ->get();

    $count = 0;
    foreach ($staleWorkers as $worker) {
        try {
            app(\App\Services\WorkerAuditService::class)->log(
                'stale_cleanup',
                $worker->id,
                $worker->worker_id,
                null,
                null,
                ['last_seen_at' => $worker->last_seen_at?->toIso8601String()]
            );
        } catch (\Throwable $e) {
            // non-blocking
        }

        $worker->workflows()->detach();
        $worker->delete();
        $count++;
    }

    $this->comment("Stale fleet workers cleaned: {$count}");
})->purpose('Remove fleet workers that have not heartbeated recently');
