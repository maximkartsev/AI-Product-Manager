<?php

use App\Models\AiJobDispatch;
use App\Models\ComfyUiGpuFleet;
use App\Models\ComfyUiWorker;
use App\Models\ComfyUiWorkerSession;
use App\Models\ComfyUiWorkflowFleet;
use App\Models\ExecutionEnvironment;
use App\Models\ProductionFleetSnapshot;
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
    $stages = ['staging', 'production'];
    $emitWorkflowMetrics = (bool) config('services.comfyui.emit_workflow_metrics', true);
    $emitFleetMetrics = (bool) config('services.comfyui.emit_fleet_metrics', true);

    $metricData = [];

    foreach ($stages as $stage) {
        $appendMetrics = function (array $dimensions, array $metrics) use (&$metricData): void {
            foreach ($metrics as $metric) {
                $metricData[] = [
                    'MetricName' => $metric[0],
                    'Dimensions' => $dimensions,
                    'Value' => $metric[1],
                    'Unit' => $metric[2],
                ];
            }
        };

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
                'queueUnits' => 0,
                'availableCapacity' => 0,
                'activeWorkers' => 0,
                'durations' => [],
                'failed' => 0,
                'total' => 0,
                'leaseExpired' => 0,
                'spotInterruptions' => 0,
                'spotSignalCount20m' => 0,
                'sloPressureMax' => 0,
            ];
        }

    // Single GROUP BY query for queue stats
    $queueStats = AiJobDispatch::query()
        ->whereNotNull('workflow_id')
        ->whereIn('status', ['queued', 'leased'])
        ->where('attempts', '<', $maxAttempts)
        ->where('stage', $stage)
        ->selectRaw('workflow_id,
            SUM(CASE WHEN status = \'queued\' THEN 1 ELSE 0 END) as queued,
            SUM(CASE WHEN status = \'leased\' THEN 1 ELSE 0 END) as leased,
            SUM(CASE WHEN status IN (\'queued\', \'leased\') THEN COALESCE(work_units, 1) ELSE 0 END) as queue_units')
        ->groupBy('workflow_id')
        ->get()
        ->keyBy('workflow_id');

    // Worker stats per workflow
    $workerStats = DB::connection('central')
        ->table('comfy_ui_workers as w')
        ->join('worker_workflows as ww', 'w.id', '=', 'ww.worker_id')
        ->where('w.is_approved', true)
        ->where('w.stage', $stage)
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
    }

    $fleetUtilizationStats = collect();
    if ($emitFleetMetrics) {
        $fleetUtilizationStats = DB::connection('central')
            ->table('comfyui_worker_sessions')
            ->where('stage', $stage)
            ->where('started_at', '>=', now()->subHours(24))
            ->selectRaw('fleet_slug, SUM(busy_seconds) as busy_seconds, SUM(running_seconds) as running_seconds')
            ->groupBy('fleet_slug')
            ->get()
            ->keyBy('fleet_slug');
    }

    // Job processing P50 per workflow (completed in last 10 min)
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

    // Processing seconds per unit (p95) per workflow (completed in last 10 min)
    $processingStats = AiJobDispatch::query()
        ->whereNotNull('workflow_id')
        ->where('status', 'completed')
        ->where('stage', $stage)
        ->whereNotNull('work_units')
        ->where('work_units', '>', 0)
        ->where(function ($q) {
            $q->whereNotNull('processing_seconds')
                ->orWhereNotNull('duration_seconds');
        })
        ->where('updated_at', '>=', now()->subMinutes(10))
        ->selectRaw('workflow_id, COALESCE(processing_seconds, duration_seconds) as processing_seconds, work_units')
        ->get()
        ->groupBy('workflow_id');

    // Error rate per workflow (last 5 min)
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

    // Lease expired count per workflow (last 5 min)
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
            ->where('stage', $stage)
            ->selectRaw('workflow_id, COUNT(*) as requeued')
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id')
            ->toArray();
    }

    // Spot risk/termination signal count per workflow (last 20 min)
    $spotSignalDispatches = WorkerAuditLog::query()
        ->whereIn('event', ['requeued', 'spot_interruption', 'spot_rebalance', 'asg_termination'])
        ->where('created_at', '>=', now()->subMinutes(20))
        ->whereNotNull('dispatch_id')
        ->selectRaw('dispatch_id')
        ->get()
        ->pluck('dispatch_id');

    $spotSignalsByWorkflow = [];
    if ($spotSignalDispatches->isNotEmpty()) {
        $spotSignalsByWorkflow = AiJobDispatch::query()
            ->whereIn('id', $spotSignalDispatches)
            ->whereNotNull('workflow_id')
            ->where('stage', $stage)
            ->selectRaw('workflow_id, COUNT(*) as signals')
            ->groupBy('workflow_id')
            ->get()
            ->keyBy('workflow_id')
            ->toArray();
    }

    foreach ($workflows as $workflow) {
        $wId = $workflow->id;
        $slug = $workflow->slug;

        $queued = (int) ($queueStats[$wId]->queued ?? 0);
        $leased = (int) ($queueStats[$wId]->leased ?? 0);
        $queueDepth = $queued + $leased;
        $queueUnits = (float) ($queueStats[$wId]->queue_units ?? 0);
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

        // P95 processing seconds per unit
        $processingSecondsPerUnitP95 = 0;
        if (isset($processingStats[$wId]) && $processingStats[$wId]->count() > 0) {
            $ratios = $processingStats[$wId]
                ->map(function ($row) {
                    $units = (float) ($row->work_units ?? 0);
                    $seconds = (float) ($row->processing_seconds ?? 0);
                    if ($units <= 0 || $seconds <= 0) {
                        return null;
                    }
                    return $seconds / $units;
                })
                ->filter()
                ->sort()
                ->values();
            if ($ratios->count() > 0) {
                $p95Index = (int) floor($ratios->count() * 0.95);
                $processingSecondsPerUnitP95 = (float) ($ratios[$p95Index] ?? 0);
            }
        }

        $estimatedWaitSecondsP95 = 0;
        if ($processingSecondsPerUnitP95 > 0) {
            $estimatedWaitSecondsP95 = $activeWorkers > 0
                ? ($queueUnits / $activeWorkers) * $processingSecondsPerUnitP95
                : $queueUnits * $processingSecondsPerUnitP95;
        }

        $workloadKind = $workflow->workload_kind;
        if (!in_array($workloadKind, ['image', 'video'], true)) {
            $mimeType = strtolower((string) ($workflow->output_mime_type ?? ''));
            $workloadKind = str_starts_with($mimeType, 'video/') ? 'video' : 'image';
        }

        $sloPressure = 0;
        if ($workloadKind === 'video') {
            $sloVideo = (float) ($workflow->slo_video_seconds_per_processing_second_p95 ?? 0);
            if ($sloVideo > 0 && $processingSecondsPerUnitP95 > 0) {
                $sloPressure = round($processingSecondsPerUnitP95 * $sloVideo, 4);
            }
        } else {
            $sloWait = (float) ($workflow->slo_p95_wait_seconds ?? 0);
            if ($sloWait > 0 && $estimatedWaitSecondsP95 > 0) {
                $sloPressure = round($estimatedWaitSecondsP95 / $sloWait, 4);
            }
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
                ['Name' => 'Stage', 'Value' => $stage],
            ];

            $workflowMetrics = [
                ['QueueDepth', $queueDepth, 'Count'],
                ['QueueDepthUnits', $queueUnits, 'Count'],
                ['BacklogPerInstance', $backlog, 'Count'],
                ['ActiveWorkers', $activeWorkers, 'Count'],
                ['AvailableCapacity', $availableCapacity, 'Count'],
                ['JobProcessingP50', $p50, 'Seconds'],
                ['ProcessingSecondsPerUnitP95', $processingSecondsPerUnitP95, 'Seconds'],
                ['EstimatedWaitSecondsP95', $estimatedWaitSecondsP95, 'Seconds'],
                ['SloPressure', $sloPressure, 'None'],
                ['ErrorRate', $errorRate, 'Percent'],
                ['LeaseExpiredCount', $leaseExpired, 'Count'],
                ['SpotInterruptionCount', $spotInterruptions, 'Count'],
            ];

            $appendMetrics($dimensions, $workflowMetrics);

            $this->info("  {$stage} {$slug}: depth={$queueDepth} units={$queueUnits} backlog={$backlog} workers={$activeWorkers} capacity={$availableCapacity} p50={$p50}s p95/unit={$processingSecondsPerUnitP95}s wait={$estimatedWaitSecondsP95}s pressure={$sloPressure} err={$errorRate}% expired={$leaseExpired} spot={$spotInterruptions}");
        }

        if ($emitFleetMetrics) {
            $fleetId = $workflowToFleet->get($wId);
            if ($fleetId && isset($fleetAggregates[$fleetId])) {
                $fleetAggregates[$fleetId]['queueDepth'] += $queueDepth;
                $fleetAggregates[$fleetId]['queueUnits'] += $queueUnits;
                $fleetAggregates[$fleetId]['failed'] += (int) ($errorStats[$wId]->failed ?? 0);
                $fleetAggregates[$fleetId]['total'] += (int) ($errorStats[$wId]->total ?? 0);
                $fleetAggregates[$fleetId]['leaseExpired'] += $leaseExpired;
                $fleetAggregates[$fleetId]['spotInterruptions'] += $spotInterruptions;
                $fleetAggregates[$fleetId]['spotSignalCount20m'] += (int) ($spotSignalsByWorkflow[$wId]['signals'] ?? 0);
                $fleetAggregates[$fleetId]['sloPressureMax'] = max(
                    $fleetAggregates[$fleetId]['sloPressureMax'],
                    $sloPressure
                );

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
            $queueUnits = (float) $stats['queueUnits'];
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

            $fleetUtilPercent = 0;
            $utilRow = $fleetUtilizationStats[$fleetSlug] ?? null;
            if ($utilRow && $utilRow->running_seconds > 0) {
                $fleetUtilPercent = round(($utilRow->busy_seconds / $utilRow->running_seconds) * 100, 2);
            }

            $sloPressureMax = (float) ($stats['sloPressureMax'] ?? 0);
            $spotSignalCount20m = (int) ($stats['spotSignalCount20m'] ?? 0);

            $dimensions = [
                ['Name' => 'FleetSlug', 'Value' => $fleetSlug],
                ['Name' => 'Stage', 'Value' => $stage],
            ];

            $fleetMetrics = [
                ['QueueDepth', $queueDepth, 'Count'],
                ['QueueDepthUnits', $queueUnits, 'Count'],
                ['BacklogPerInstance', $backlog, 'Count'],
                ['ActiveWorkers', $activeWorkers, 'Count'],
                ['AvailableCapacity', $availableCapacity, 'Count'],
                ['JobProcessingP50', $p50, 'Seconds'],
                ['FleetSloPressureMax', $sloPressureMax, 'None'],
                ['FleetUtilization', $fleetUtilPercent, 'Percent'],
                ['FleetSpotSignalCount20m', $spotSignalCount20m, 'Count'],
                ['ErrorRate', $errorRate, 'Percent'],
                ['LeaseExpiredCount', (int) $stats['leaseExpired'], 'Count'],
                ['SpotInterruptionCount', (int) $stats['spotInterruptions'], 'Count'],
            ];

            $appendMetrics($dimensions, $fleetMetrics);

            $this->info("  {$stage} fleet {$fleetSlug}: depth={$queueDepth} units={$queueUnits} backlog={$backlog} workers={$activeWorkers} capacity={$availableCapacity} p50={$p50}s pressure={$sloPressureMax} util={$fleetUtilPercent}% err={$errorRate}% expired={$stats['leaseExpired']} spot={$stats['spotInterruptions']} signals20m={$spotSignalCount20m}");
        }
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
