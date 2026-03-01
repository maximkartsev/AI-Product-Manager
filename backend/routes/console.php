<?php

use App\Models\AiJobDispatch;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorker;
use App\Models\ComfyUiWorkerSession;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\ExecutionEnvironment;
use App\Models\ProductionFleetSnapshot;
use App\Models\WorkerAuditLog;
use App\Services\ComfyUiCloudWatchRegionResolver;
use App\Services\ComfyUiFleetCloudWatchMetricsBuilder;
use App\Services\Observability\ActionLogAnomalyDetector;
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

Artisan::command('studio:scan-action-log-anomalies {--lookback-minutes=30}', function () {
    $lookbackMinutes = (int) $this->option('lookback-minutes');
    if ($lookbackMinutes < 5) {
        $lookbackMinutes = 5;
    }
    if ($lookbackMinutes > 1440) {
        $lookbackMinutes = 1440;
    }

    $result = app(ActionLogAnomalyDetector::class)->scanRecent($lookbackMinutes);
    $this->info('Action log anomaly scan completed.');
    $this->line(json_encode($result, JSON_PRETTY_PRINT));

    return 0;
})->purpose('Scan recent action logs and emit anomaly guidance');

/*
|--------------------------------------------------------------------------
| Workers: Publish CloudWatch Metrics
|--------------------------------------------------------------------------
*/
Artisan::command('workers:publish-metrics', function () {
    $maxAttempts = (int) config('services.comfyui.max_attempts', 3);
    $namespace = 'ComfyUI/Workers';
    $stages = ['staging', 'production'];
    $metricsBuilder = app(ComfyUiFleetCloudWatchMetricsBuilder::class);
    $metricData = [];

    foreach ($stages as $stage) {
        $fleets = ComfyUiGpuFleet::query()
            ->where('stage', $stage)
            ->get(['id', 'slug']);

        if ($fleets->isEmpty()) {
            continue;
        }

        $workflowToFleet = ComfyUiWorkflowFleet::query()
            ->where('stage', $stage)
            ->pluck('fleet_id', 'workflow_id');

        $fleetAggregates = [];
        foreach ($fleets as $fleet) {
            $fleetAggregates[$fleet->id] = [
                'slug' => $fleet->slug,
                'queueDepth' => 0,
                'durations' => [],
                'failed' => 0,
                'total' => 0,
                'leaseExpired' => 0,
                'spotInterruptions' => 0,
            ];
        }

        $queueStats = AiJobDispatch::query()
            ->whereNotNull('workflow_id')
            ->whereIn('status', ['queued', 'leased'])
            ->where('attempts', '<', $maxAttempts)
            ->where('stage', $stage)
            ->selectRaw('workflow_id,
                SUM(CASE WHEN status = \'queued\' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = \'leased\' THEN 1 ELSE 0 END) as leased')
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id');

        $durationStats = AiJobDispatch::query()
            ->whereNotNull('workflow_id')
            ->where('status', 'completed')
            ->where('stage', $stage)
            ->where(function ($q) {
                $q->whereNotNull('processing_seconds')
                    ->orWhereNotNull('duration_seconds');
            })
            ->where('updated_at', '>=', now()->subMinutes(10))
            ->selectRaw('workflow_id, COALESCE(processing_seconds, duration_seconds) as duration_seconds')
            ->orderBy('workflow_id')
            ->orderBy('duration_seconds')
            ->get()
            ->groupBy('workflow_id');

        $errorStats = AiJobDispatch::query()
            ->whereNotNull('workflow_id')
            ->whereIn('status', ['completed', 'failed'])
            ->where('stage', $stage)
            ->where('updated_at', '>=', now()->subMinutes(5))
            ->selectRaw('workflow_id,
                SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed,
                COUNT(*) as total')
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id');

        $leaseExpiredStats = AiJobDispatch::query()
            ->whereNotNull('workflow_id')
            ->where('status', 'leased')
            ->where('stage', $stage)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->selectRaw('workflow_id, COUNT(*) as expired')
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id');

        $spotDispatchIds = WorkerAuditLog::query()
            ->where('event', 'requeued')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('dispatch_id')
            ->pluck('dispatch_id');

        $spotByWorkflow = [];
        if ($spotDispatchIds->isNotEmpty()) {
            $spotByWorkflow = AiJobDispatch::query()
                ->whereIn('id', $spotDispatchIds)
                ->whereNotNull('workflow_id')
                ->where('stage', $stage)
                ->selectRaw('workflow_id, COUNT(*) as requeued')
                ->groupBy('workflow_id')
                ->get()
                ->keyBy('workflow_id')
                ->toArray();
        }

        foreach ($workflowToFleet as $workflowId => $fleetId) {
            if (!$fleetId || !isset($fleetAggregates[$fleetId])) {
                continue;
            }

            $queued = (int) ($queueStats[$workflowId]->queued ?? 0);
            $leased = (int) ($queueStats[$workflowId]->leased ?? 0);
            $fleetAggregates[$fleetId]['queueDepth'] += $queued + $leased;
            $fleetAggregates[$fleetId]['failed'] += (int) ($errorStats[$workflowId]->failed ?? 0);
            $fleetAggregates[$fleetId]['total'] += (int) ($errorStats[$workflowId]->total ?? 0);
            $fleetAggregates[$fleetId]['leaseExpired'] += (int) ($leaseExpiredStats[$workflowId]->expired ?? 0);
            $fleetAggregates[$fleetId]['spotInterruptions'] += (int) ($spotByWorkflow[$workflowId]['requeued'] ?? 0);

            if (isset($durationStats[$workflowId]) && $durationStats[$workflowId]->count() > 0) {
                $fleetAggregates[$fleetId]['durations'] = array_merge(
                    $fleetAggregates[$fleetId]['durations'],
                    $durationStats[$workflowId]->pluck('duration_seconds')->all()
                );
            }
        }

        $workerFleetSub = DB::connection('central')
            ->table('comfy_ui_workers as w')
            ->join('worker_workflows as ww', 'w.id', '=', 'ww.worker_id')
            ->join('comfyui_workflow_fleets as wf', 'wf.workflow_id', '=', 'ww.workflow_id')
            ->where('wf.stage', $stage)
            ->where('w.stage', $stage)
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

            $fleetMetrics = $metricsBuilder->build($fleetSlug, $stage, [
                'queueDepth' => $queueDepth,
                'backlogPerInstance' => $backlog,
                'activeWorkers' => $activeWorkers,
                'availableCapacity' => $availableCapacity,
                'jobProcessingP50' => $p50,
                'errorRate' => $errorRate,
                'leaseExpiredCount' => (int) $stats['leaseExpired'],
                'spotInterruptionCount' => (int) $stats['spotInterruptions'],
            ]);
            $metricData = array_merge($metricData, $fleetMetrics);

            $this->info("  {$stage} fleet {$fleetSlug}: depth={$queueDepth} backlog={$backlog} workers={$activeWorkers} capacity={$availableCapacity} p50={$p50}s err={$errorRate}% expired={$stats['leaseExpired']} spot={$stats['spotInterruptions']}");
        }
    }

    if (empty($metricData)) {
        $this->comment('No metrics to publish.');
        return;
    }

    // Publish to CloudWatch in batches of 20 (API limit)
    try {
        $region = app(ComfyUiCloudWatchRegionResolver::class)->resolve();
        $cw = new \Aws\CloudWatch\CloudWatchClient([
            'region' => $region,
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
})->purpose('Publish worker metrics to CloudWatch (fleet only)');

/*
|--------------------------------------------------------------------------
| Workers: Snapshot Production Fleets
|--------------------------------------------------------------------------
*/
Artisan::command('workers:snapshot-production-fleets', function () {
    $capturedAt = now();
    $snapshotsCreated = 0;

    $productionFleets = ComfyUiGpuFleet::query()
        ->where('stage', 'production')
        ->get();

    foreach ($productionFleets as $fleet) {
        $workflowIds = ComfyUiWorkflowFleet::query()
            ->where('fleet_id', $fleet->id)
            ->where('stage', 'production')
            ->pluck('workflow_id')
            ->filter()
            ->values();

        $queueStats = AiJobDispatch::query()
            ->where('stage', 'production')
            ->whereIn('workflow_id', $workflowIds)
            ->whereIn('status', ['queued', 'leased'])
            ->selectRaw('COUNT(*) as queue_depth, SUM(COALESCE(work_units, 1)) as queue_units')
            ->first();

        $processingStats = AiJobDispatch::query()
            ->where('stage', 'production')
            ->whereIn('workflow_id', $workflowIds)
            ->where('status', 'completed')
            ->where('updated_at', '>=', now()->subMinutes(10))
            ->where(function ($q) {
                $q->whereNotNull('processing_seconds')
                    ->orWhereNotNull('duration_seconds');
            })
            ->selectRaw('COALESCE(processing_seconds, duration_seconds) as processing_seconds')
            ->orderBy('processing_seconds')
            ->pluck('processing_seconds')
            ->filter()
            ->values();

        $waitStats = AiJobDispatch::query()
            ->where('stage', 'production')
            ->whereIn('workflow_id', $workflowIds)
            ->whereNotNull('queue_wait_seconds')
            ->where('updated_at', '>=', now()->subMinutes(10))
            ->orderBy('queue_wait_seconds')
            ->pluck('queue_wait_seconds')
            ->filter()
            ->values();

        $p95Processing = null;
        if ($processingStats->count() > 0) {
            $idx = (int) floor(($processingStats->count() - 1) * 0.95);
            $p95Processing = (float) $processingStats[$idx];
        }

        $p95QueueWait = null;
        if ($waitStats->count() > 0) {
            $idx = (int) floor(($waitStats->count() - 1) * 0.95);
            $p95QueueWait = (float) $waitStats[$idx];
        }

        $interruptionsCount = WorkerAuditLog::query()
            ->whereIn('event', ['spot_interruption', 'requeued'])
            ->where('created_at', '>=', now()->subHour())
            ->count();
        $rebalanceCount = WorkerAuditLog::query()
            ->where('event', 'spot_rebalance')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $environment = ExecutionEnvironment::query()
            ->where('kind', 'prod_asg')
            ->where('stage', 'production')
            ->where('fleet_slug', $fleet->slug)
            ->first();
        if (!$environment) {
            $environment = ExecutionEnvironment::query()->create([
                'name' => 'Production ASG - ' . $fleet->slug,
                'kind' => 'prod_asg',
                'stage' => 'production',
                'fleet_slug' => $fleet->slug,
                'configuration_json' => [
                    'instance_types' => $fleet->instance_types,
                    'max_size' => $fleet->max_size,
                    'warmup_seconds' => $fleet->warmup_seconds,
                    'template_slug' => $fleet->template_slug,
                ],
                'is_active' => true,
            ]);
        }

        ProductionFleetSnapshot::query()->create([
            'execution_environment_id' => $environment->id,
            'fleet_slug' => $fleet->slug,
            'stage' => 'production',
            'captured_at' => $capturedAt,
            'config_json' => [
                'instance_types' => $fleet->instance_types,
                'max_size' => $fleet->max_size,
                'warmup_seconds' => $fleet->warmup_seconds,
                'backlog_target' => $fleet->backlog_target,
                'scale_to_zero_minutes' => $fleet->scale_to_zero_minutes,
                'template_slug' => $fleet->template_slug,
            ],
            'composition_json' => [
                'workflow_ids' => $workflowIds->all(),
                'active_workflow_count' => $workflowIds->count(),
            ],
            'metrics_json' => [
                'queue_depth' => (int) ($queueStats->queue_depth ?? 0),
                'queue_units' => (float) ($queueStats->queue_units ?? 0),
                'p95_queue_wait_seconds' => $p95QueueWait,
                'p95_processing_seconds' => $p95Processing,
                'interruptions_count_last_hour' => $interruptionsCount,
                'rebalance_recommendations_count_last_hour' => $rebalanceCount,
                'spot_discount_estimate' => null,
            ],
            'queue_depth' => (int) ($queueStats->queue_depth ?? 0),
            'queue_units' => (float) ($queueStats->queue_units ?? 0),
            'p95_queue_wait_seconds' => $p95QueueWait,
            'p95_processing_seconds' => $p95Processing,
            'interruptions_count' => $interruptionsCount,
            'rebalance_recommendations_count' => $rebalanceCount,
            'spot_discount_estimate' => null,
        ]);
        $snapshotsCreated++;
    }

    $this->info("Production fleet snapshots captured: {$snapshotsCreated}");
})->purpose('Persist production fleet configuration + aggregate telemetry snapshots');

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

        $session = ComfyUiWorkerSession::query()
            ->where('worker_identifier', $worker->worker_id)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
        if ($session) {
            $endedAt = now();
            $session->ended_at = $endedAt;
            if ($session->started_at) {
                $session->running_seconds = $endedAt->diffInSeconds($session->started_at);
                if ($session->running_seconds > 0) {
                    $session->utilization = round($session->busy_seconds / $session->running_seconds, 4);
                }
            }
            $session->save();
        }

        $worker->workflows()->detach();
        $worker->delete();
        $count++;
    }

    $this->comment("Stale fleet workers cleaned: {$count}");
})->purpose('Remove fleet workers that have not heartbeated recently');
