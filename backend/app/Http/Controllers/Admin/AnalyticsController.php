<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
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
}
