<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\AiJob;
use App\Models\AiJobDispatch;
use App\Models\Effect;
use App\Models\PartnerUsageEvent;
use App\Models\PartnerUsagePrice;
use App\Models\Tenant;
use App\Models\TokenTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

class EconomicsAnalyticsController extends BaseController
{
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

        $pricingByKey = [];
        $partnerPricingRows = PartnerUsagePrice::query()->get([
            'provider',
            'node_class_type',
            'model',
            'usd_per_1m_input_tokens',
            'usd_per_1m_output_tokens',
            'usd_per_1m_total_tokens',
            'usd_per_credit',
        ]);
        foreach ($partnerPricingRows as $priceRow) {
            $providerKey = strtolower((string) ($priceRow->provider ?? 'unknown'));
            $nodeClassKey = strtolower((string) ($priceRow->node_class_type ?? 'unknown'));
            $modelKey = strtolower((string) ($priceRow->model ?? ''));
            $pricingByKey[$providerKey . '|' . $nodeClassKey . '|' . $modelKey] = [
                'usd_per_1m_input_tokens' => $priceRow->usd_per_1m_input_tokens,
                'usd_per_1m_output_tokens' => $priceRow->usd_per_1m_output_tokens,
                'usd_per_1m_total_tokens' => $priceRow->usd_per_1m_total_tokens,
                'usd_per_credit' => $priceRow->usd_per_credit,
            ];
        }

        $partnerUsageQuery = PartnerUsageEvent::query()->whereNotNull('effect_id');
        if ($from) {
            $partnerUsageQuery->where('created_at', '>=', $from);
        }
        if ($to) {
            $partnerUsageQuery->where('created_at', '<=', $to . ' 23:59:59');
        }

        $partnerUsageRows = $partnerUsageQuery
            ->select('effect_id', 'provider', 'node_class_type')
            ->selectRaw("COALESCE(model, '') as model_key")
            ->selectRaw('SUM(COALESCE(input_tokens, 0)) as input_tokens')
            ->selectRaw('SUM(COALESCE(output_tokens, 0)) as output_tokens')
            ->selectRaw('SUM(COALESCE(total_tokens, COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0))) as total_tokens')
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->groupBy('effect_id', 'provider', 'node_class_type', DB::raw("COALESCE(model, '')"))
            ->get();

        $partnerUsageByEffect = [];
        $totalPartnerUsageInputTokens = 0;
        $totalPartnerUsageOutputTokens = 0;
        $totalPartnerUsageTokens = 0;
        $totalPartnerUsageCredits = 0.0;
        $totalPartnerUsageCostUsd = 0.0;
        $totalPartnerUsageCostUsdReported = 0.0;

        foreach ($partnerUsageRows as $usageRow) {
            $effectId = (int) $usageRow->effect_id;
            $providerKey = strtolower((string) ($usageRow->provider ?? 'unknown'));
            $nodeClassKey = strtolower((string) ($usageRow->node_class_type ?? 'unknown'));
            $modelKey = strtolower((string) ($usageRow->model_key ?? ''));
            $pricing = $pricingByKey[$providerKey . '|' . $nodeClassKey . '|' . $modelKey] ?? null;

            $inputTokens = (int) round((float) ($usageRow->input_tokens ?? 0));
            $outputTokens = (int) round((float) ($usageRow->output_tokens ?? 0));
            $totalTokens = (int) round((float) ($usageRow->total_tokens ?? 0));
            $credits = (float) ($usageRow->credits ?? 0);
            $costUsdReported = (float) ($usageRow->cost_usd_reported ?? 0);

            $estimatedCostUsd = 0.0;
            $hasPricing = false;
            if ($pricing) {
                $inputRate = $pricing['usd_per_1m_input_tokens'];
                $outputRate = $pricing['usd_per_1m_output_tokens'];
                $totalRate = $pricing['usd_per_1m_total_tokens'];
                $creditRate = $pricing['usd_per_credit'];

                if ($inputRate !== null) {
                    $estimatedCostUsd += ((float) $inputRate * $inputTokens) / 1000000;
                    $hasPricing = true;
                }
                if ($outputRate !== null) {
                    $estimatedCostUsd += ((float) $outputRate * $outputTokens) / 1000000;
                    $hasPricing = true;
                }
                if (!$hasPricing && $totalRate !== null) {
                    $estimatedCostUsd += ((float) $totalRate * $totalTokens) / 1000000;
                    $hasPricing = true;
                }
                if ($creditRate !== null) {
                    $estimatedCostUsd += ((float) $creditRate * $credits);
                    $hasPricing = true;
                }
            }
            if (!$hasPricing && $costUsdReported > 0) {
                $estimatedCostUsd = $costUsdReported;
            }

            if (!isset($partnerUsageByEffect[$effectId])) {
                $partnerUsageByEffect[$effectId] = [
                    'inputTokens' => 0,
                    'outputTokens' => 0,
                    'totalTokens' => 0,
                    'credits' => 0.0,
                    'costUsd' => 0.0,
                    'costUsdReported' => 0.0,
                ];
            }

            $partnerUsageByEffect[$effectId]['inputTokens'] += $inputTokens;
            $partnerUsageByEffect[$effectId]['outputTokens'] += $outputTokens;
            $partnerUsageByEffect[$effectId]['totalTokens'] += $totalTokens;
            $partnerUsageByEffect[$effectId]['credits'] += $credits;
            $partnerUsageByEffect[$effectId]['costUsd'] += $estimatedCostUsd;
            $partnerUsageByEffect[$effectId]['costUsdReported'] += $costUsdReported;

            $totalPartnerUsageInputTokens += $inputTokens;
            $totalPartnerUsageOutputTokens += $outputTokens;
            $totalPartnerUsageTokens += $totalTokens;
            $totalPartnerUsageCredits += $credits;
            $totalPartnerUsageCostUsd += $estimatedCostUsd;
            $totalPartnerUsageCostUsdReported += $costUsdReported;
        }

        $effectIds = array_values(array_unique(array_merge(array_keys($byEffectAgg), array_keys($partnerUsageByEffect))));
        $effects = $effectIds
            ? Effect::with('workflow.fleets')->whereIn('id', $effectIds)->get()->keyBy('id')
            : collect();

        $byEffectResult = [];
        $totalPartnerCostUsd = 0.0;

        foreach ($effectIds as $effectId) {
            $stats = $byEffectAgg[$effectId] ?? [
                'effectId' => $effectId,
                'totalTokens' => 0,
                'totalJobs' => 0,
                'totalProcessingSeconds' => 0,
                'totalQueueWaitSeconds' => 0,
                'totalWorkUnits' => 0.0,
                'workUnitKind' => null,
            ];
            $partnerUsageStats = $partnerUsageByEffect[$effectId] ?? [
                'inputTokens' => 0,
                'outputTokens' => 0,
                'totalTokens' => 0,
                'credits' => 0.0,
                'costUsd' => 0.0,
                'costUsdReported' => 0.0,
            ];

            $effect = $effects->get($effectId);
            $workflow = $effect?->workflow;
            $partnerCostPerWorkUnit = $workflow?->partner_cost_per_work_unit;
            $partnerCostUsd = null;
            if ($partnerCostPerWorkUnit !== null && $stats['totalWorkUnits'] > 0) {
                $partnerCostUsd = round($partnerCostPerWorkUnit * $stats['totalWorkUnits'], 4);
                $totalPartnerCostUsd += $partnerCostUsd;
            }
            $partnerUsageCostUsd = $partnerUsageStats['costUsd'] > 0
                ? round((float) $partnerUsageStats['costUsd'], 4)
                : null;
            $partnerUsageCostUsdReported = $partnerUsageStats['costUsdReported'] > 0
                ? round((float) $partnerUsageStats['costUsdReported'], 4)
                : null;
            $partnerCostUsdTotal = null;
            if ($partnerCostUsd !== null || $partnerUsageCostUsd !== null) {
                $partnerCostUsdTotal = round((float) ($partnerCostUsd ?? 0) + (float) ($partnerUsageCostUsd ?? 0), 4);
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
                'partnerUsageInputTokens' => (int) $partnerUsageStats['inputTokens'],
                'partnerUsageOutputTokens' => (int) $partnerUsageStats['outputTokens'],
                'partnerUsageTotalTokens' => (int) $partnerUsageStats['totalTokens'],
                'partnerUsageCredits' => round((float) $partnerUsageStats['credits'], 6),
                'partnerUsageCostUsd' => $partnerUsageCostUsd,
                'partnerUsageCostUsdReported' => $partnerUsageCostUsdReported,
                'partnerCostUsdTotal' => $partnerCostUsdTotal,
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
                'totalPartnerUsageInputTokens' => $totalPartnerUsageInputTokens,
                'totalPartnerUsageOutputTokens' => $totalPartnerUsageOutputTokens,
                'totalPartnerUsageTokens' => $totalPartnerUsageTokens,
                'totalPartnerUsageCredits' => $totalPartnerUsageCredits > 0
                    ? round($totalPartnerUsageCredits, 6)
                    : null,
                'totalPartnerUsageCostUsd' => $totalPartnerUsageCostUsd > 0
                    ? round($totalPartnerUsageCostUsd, 4)
                    : null,
                'totalPartnerUsageCostUsdReported' => $totalPartnerUsageCostUsdReported > 0
                    ? round($totalPartnerUsageCostUsdReported, 4)
                    : null,
                'totalPartnerCostUsdCombined' => ($totalPartnerCostUsd > 0 || $totalPartnerUsageCostUsd > 0)
                    ? round($totalPartnerCostUsd + $totalPartnerUsageCostUsd, 4)
                    : null,
            ],
        ], 'Unit economics analytics retrieved successfully');
    }
}
