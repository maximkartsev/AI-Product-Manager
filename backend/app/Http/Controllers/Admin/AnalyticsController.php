<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\Tenant;
use App\Models\TokenTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

class AnalyticsController extends BaseController
{
    public function tokenSpending(Request $request): JsonResponse
    {
        $from = $request->get('from');
        $to = $request->get('to');
        $granularity = $request->get('granularity', 'day');

        $dateFormat = match ($granularity) {
            'week' => '%x-W%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $tenants = Tenant::all();

        $timeSeriesAgg = [];
        $byEffectAgg = [];
        $totalTokens = 0;

        $tenancy = app(Tenancy::class);

        foreach ($tenants as $tenant) {
            $tenancy->initialize($tenant);

            try {
                $query = TokenTransaction::query()
                    ->whereIn('token_transactions.type', ['JOB_RESERVE', 'JOB_CONSUME']);

                if ($from) {
                    $query->where('token_transactions.created_at', '>=', $from);
                }
                if ($to) {
                    $query->where('token_transactions.created_at', '<=', $to . ' 23:59:59');
                }

                // Time series aggregation
                $timeSeries = (clone $query)
                    ->select(
                        DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as bucket"),
                        DB::raw('SUM(ABS(amount)) as total_tokens')
                    )
                    ->groupBy('bucket')
                    ->get();

                foreach ($timeSeries as $row) {
                    $bucket = $row->bucket;
                    if (!isset($timeSeriesAgg[$bucket])) {
                        $timeSeriesAgg[$bucket] = 0;
                    }
                    $timeSeriesAgg[$bucket] += (int) $row->total_tokens;
                }

                // By effect aggregation
                $byEffect = (clone $query)
                    ->join('ai_jobs', 'token_transactions.job_id', '=', 'ai_jobs.id')
                    ->select(
                        'ai_jobs.effect_id',
                        DB::raw('SUM(ABS(token_transactions.amount)) as total_tokens')
                    )
                    ->groupBy('ai_jobs.effect_id')
                    ->get();

                foreach ($byEffect as $row) {
                    $effectId = (int) $row->effect_id;
                    if (!isset($byEffectAgg[$effectId])) {
                        $byEffectAgg[$effectId] = 0;
                    }
                    $byEffectAgg[$effectId] += (int) $row->total_tokens;
                }

                // Total
                $tenantTotal = (clone $query)->sum(DB::raw('ABS(amount)'));
                $totalTokens += (int) $tenantTotal;
            } finally {
                $tenancy->end();
            }
        }

        // Build time series response
        $timeSeriesResult = [];
        ksort($timeSeriesAgg);
        foreach ($timeSeriesAgg as $bucket => $tokens) {
            $timeSeriesResult[] = [
                'bucket' => $bucket,
                'totalTokens' => $tokens,
            ];
        }

        // Enrich effect data with names from central DB
        $effectIds = array_keys($byEffectAgg);
        $effectNames = [];
        if (!empty($effectIds)) {
            $effects = Effect::whereIn('id', $effectIds)->pluck('name', 'id');
            foreach ($effects as $id => $name) {
                $effectNames[$id] = $name;
            }
        }

        $byEffectResult = [];
        arsort($byEffectAgg);
        foreach ($byEffectAgg as $effectId => $tokens) {
            $byEffectResult[] = [
                'effectId' => $effectId,
                'effectName' => $effectNames[$effectId] ?? "Effect #{$effectId}",
                'totalTokens' => $tokens,
            ];
        }

        return $this->sendResponse([
            'timeSeries' => $timeSeriesResult,
            'byEffect' => $byEffectResult,
            'totalTokens' => $totalTokens,
        ], 'Token spending analytics retrieved successfully');
    }

    public function unitEconomics(Request $request): JsonResponse
    {
        $from = $request->get('from');
        $to = $request->get('to');

        $tenants = Tenant::all();
        $tenancy = app(Tenancy::class);

        $byEffectAgg = [];
        $totalTokens = 0;
        $totalJobs = 0;
        $totalProcessingSeconds = 0;
        $totalQueueWaitSeconds = 0;
        $totalWorkUnits = 0.0;

        $ensureEffectRow = function (int $effectId) use (&$byEffectAgg): void {
            if (!isset($byEffectAgg[$effectId])) {
                $byEffectAgg[$effectId] = [
                    'effectId' => $effectId,
                    'totalTokens' => 0,
                    'totalJobs' => 0,
                    'totalProcessingSeconds' => 0,
                    'totalQueueWaitSeconds' => 0,
                    'totalWorkUnits' => 0.0,
                    'workUnitKind' => null,
                ];
            }
        };

        foreach ($tenants as $tenant) {
            $tenancy->initialize($tenant);

            try {
                $tokenQuery = TokenTransaction::query()
                    ->whereIn('token_transactions.type', ['JOB_RESERVE', 'JOB_CONSUME']);

                if ($from) {
                    $tokenQuery->where('token_transactions.created_at', '>=', $from);
                }
                if ($to) {
                    $tokenQuery->where('token_transactions.created_at', '<=', $to . ' 23:59:59');
                }

                $tokenRows = (clone $tokenQuery)
                    ->join('ai_jobs', 'token_transactions.job_id', '=', 'ai_jobs.id')
                    ->select(
                        'ai_jobs.effect_id',
                        DB::raw('SUM(ABS(token_transactions.amount)) as total_tokens')
                    )
                    ->groupBy('ai_jobs.effect_id')
                    ->get();

                foreach ($tokenRows as $row) {
                    $effectId = (int) $row->effect_id;
                    $tokenCount = (int) $row->total_tokens;
                    $ensureEffectRow($effectId);
                    $byEffectAgg[$effectId]['totalTokens'] += $tokenCount;
                    $totalTokens += $tokenCount;
                }

                $dispatchQuery = AiJobDispatch::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereNotNull('finished_at');

                if ($from) {
                    $dispatchQuery->where('finished_at', '>=', $from);
                }
                if ($to) {
                    $dispatchQuery->where('finished_at', '<=', $to . ' 23:59:59');
                }

                $dispatches = $dispatchQuery->get([
                    'tenant_job_id',
                    'workflow_id',
                    'processing_seconds',
                    'duration_seconds',
                    'queue_wait_seconds',
                    'work_units',
                    'work_unit_kind',
                ]);

                if ($dispatches->isEmpty()) {
                    continue;
                }

                $jobIds = $dispatches->pluck('tenant_job_id')->filter()->unique()->values()->all();
                if (empty($jobIds)) {
                    continue;
                }

                $jobEffectMap = AiJob::query()
                    ->whereIn('id', $jobIds)
                    ->pluck('effect_id', 'id');

                foreach ($dispatches as $dispatch) {
                    $jobId = $dispatch->tenant_job_id;
                    $effectId = $jobId ? $jobEffectMap->get($jobId) : null;
                    if (!$effectId) {
                        continue;
                    }

                    $processingSeconds = $dispatch->processing_seconds ?? $dispatch->duration_seconds ?? 0;
                    $queueWaitSeconds = $dispatch->queue_wait_seconds ?? 0;
                    $workUnits = $dispatch->work_units ?? 1.0;
                    $workUnitKind = $dispatch->work_unit_kind ?: null;

                    $ensureEffectRow((int) $effectId);
                    $byEffectAgg[$effectId]['totalJobs'] += 1;
                    $byEffectAgg[$effectId]['totalProcessingSeconds'] += (int) $processingSeconds;
                    $byEffectAgg[$effectId]['totalQueueWaitSeconds'] += (int) $queueWaitSeconds;
                    $byEffectAgg[$effectId]['totalWorkUnits'] += (float) $workUnits;
                    if ($workUnitKind && !$byEffectAgg[$effectId]['workUnitKind']) {
                        $byEffectAgg[$effectId]['workUnitKind'] = $workUnitKind;
                    }

                    $totalJobs += 1;
                    $totalProcessingSeconds += (int) $processingSeconds;
                    $totalQueueWaitSeconds += (int) $queueWaitSeconds;
                    $totalWorkUnits += (float) $workUnits;
                }
            } finally {
                $tenancy->end();
            }
        }

        $effectIds = array_keys($byEffectAgg);
        $effects = $effectIds
            ? Effect::with('workflow.fleets')->whereIn('id', $effectIds)->get()->keyBy('id')
            : collect();

        $byEffectResult = [];
        $totalPartnerCostUsd = 0.0;

        foreach ($byEffectAgg as $effectId => $stats) {
            $effect = $effects->get($effectId);
            $workflow = $effect?->workflow;
            $partnerCostPerWorkUnit = $workflow?->partner_cost_per_work_unit;
            $partnerCostUsd = null;
            if ($partnerCostPerWorkUnit !== null && $stats['totalWorkUnits'] > 0) {
                $partnerCostUsd = round($partnerCostPerWorkUnit * $stats['totalWorkUnits'], 4);
                $totalPartnerCostUsd += $partnerCostUsd;
            }

            $fleetSlugs = [];
            $fleetInstanceTypes = [];
            if ($workflow) {
                foreach ($workflow->fleets as $fleet) {
                    if ($fleet->slug) {
                        $fleetSlugs[] = $fleet->slug;
                    }
                    foreach ((array) $fleet->instance_types as $instanceType) {
                        if ($instanceType) {
                            $fleetInstanceTypes[$instanceType] = true;
                        }
                    }
                }
            }

            $avgProcessingSeconds = $stats['totalJobs'] > 0
                ? round($stats['totalProcessingSeconds'] / $stats['totalJobs'], 2)
                : null;
            $avgProcessingSecondsPerUnit = $stats['totalWorkUnits'] > 0
                ? round($stats['totalProcessingSeconds'] / $stats['totalWorkUnits'], 4)
                : null;
            $avgTokensPerJob = $stats['totalJobs'] > 0
                ? round($stats['totalTokens'] / $stats['totalJobs'], 2)
                : null;
            $avgTokensPerWorkUnit = $stats['totalWorkUnits'] > 0
                ? round($stats['totalTokens'] / $stats['totalWorkUnits'], 4)
                : null;

            $byEffectResult[] = [
                'effectId' => $effectId,
                'effectName' => $effect?->name ?? "Effect #{$effectId}",
                'workflowId' => $workflow?->id,
                'workflowName' => $workflow?->name,
                'workloadKind' => $workflow?->workload_kind,
                'workUnitKind' => $stats['workUnitKind'],
                'totalTokens' => $stats['totalTokens'],
                'totalJobs' => $stats['totalJobs'],
                'totalProcessingSeconds' => $stats['totalProcessingSeconds'],
                'totalQueueWaitSeconds' => $stats['totalQueueWaitSeconds'],
                'totalWorkUnits' => round($stats['totalWorkUnits'], 4),
                'avgProcessingSeconds' => $avgProcessingSeconds,
                'avgProcessingSecondsPerUnit' => $avgProcessingSecondsPerUnit,
                'avgTokensPerJob' => $avgTokensPerJob,
                'avgTokensPerWorkUnit' => $avgTokensPerWorkUnit,
                'partnerCostPerWorkUnit' => $partnerCostPerWorkUnit,
                'partnerCostUsd' => $partnerCostUsd,
                'fleetSlugs' => array_values(array_unique($fleetSlugs)),
                'fleetInstanceTypes' => array_values(array_unique(array_keys($fleetInstanceTypes))),
            ];
        }

        usort($byEffectResult, fn ($a, $b) => $b['totalTokens'] <=> $a['totalTokens']);

        return $this->sendResponse([
            'byEffect' => $byEffectResult,
            'totals' => [
                'totalTokens' => $totalTokens,
                'totalJobs' => $totalJobs,
                'totalProcessingSeconds' => $totalProcessingSeconds,
                'totalQueueWaitSeconds' => $totalQueueWaitSeconds,
                'totalWorkUnits' => round($totalWorkUnits, 4),
                'totalPartnerCostUsd' => $totalPartnerCostUsd > 0 ? round($totalPartnerCostUsd, 4) : null,
            ],
        ], 'Unit economics analytics retrieved successfully');
    }
}
