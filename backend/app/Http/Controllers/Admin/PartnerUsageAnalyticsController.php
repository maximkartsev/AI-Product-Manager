<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ComfyUiWorker;
use App\Models\Effect;
use App\Models\PartnerUsageEvent;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PartnerUsageAnalyticsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => 'date|nullable',
            'to' => 'date|nullable',
            'effect_id' => 'integer|nullable',
            'workflow_id' => 'integer|nullable',
            'user_id' => 'integer|nullable',
            'worker_id' => 'string|nullable|max:255',
            'provider' => 'string|nullable|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $filters = $validator->validated();
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $query = PartnerUsageEvent::query();
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        if (!empty($filters['effect_id'])) {
            $query->where('effect_id', (int) $filters['effect_id']);
        }
        if (!empty($filters['workflow_id'])) {
            $query->where('workflow_id', (int) $filters['workflow_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['worker_id'])) {
            $query->where('worker_id', (string) $filters['worker_id']);
        }
        if (!empty($filters['provider'])) {
            $query->where('provider', strtolower((string) $filters['provider']));
        }

        $totalTokenExpr = 'COALESCE(total_tokens, COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0))';

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw('SUM(COALESCE(input_tokens, 0)) as input_tokens')
            ->selectRaw('SUM(COALESCE(output_tokens, 0)) as output_tokens')
            ->selectRaw("SUM({$totalTokenExpr}) as total_tokens")
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->first();

        $byProviderRaw = (clone $query)
            ->selectRaw('provider')
            ->selectRaw('node_class_type')
            ->selectRaw("COALESCE(model, '') as model")
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw('SUM(COALESCE(input_tokens, 0)) as input_tokens')
            ->selectRaw('SUM(COALESCE(output_tokens, 0)) as output_tokens')
            ->selectRaw("SUM({$totalTokenExpr}) as total_tokens")
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->selectRaw('MAX(created_at) as last_seen_at')
            ->groupBy('provider', 'node_class_type', 'model')
            ->orderByDesc('events_count')
            ->get();
        $byProviderNodeModel = $byProviderRaw->map(function ($row) {
            return [
                'provider' => $row->provider,
                'nodeClassType' => $row->node_class_type,
                'model' => $row->model !== '' ? $row->model : null,
                'eventsCount' => (int) ($row->events_count ?? 0),
                'inputTokens' => (int) ($row->input_tokens ?? 0),
                'outputTokens' => (int) ($row->output_tokens ?? 0),
                'totalTokens' => (int) ($row->total_tokens ?? 0),
                'credits' => round((float) ($row->credits ?? 0), 6),
                'costUsdReported' => round((float) ($row->cost_usd_reported ?? 0), 8),
                'lastSeenAt' => $row->last_seen_at,
            ];
        })->values();

        $byEffectRaw = (clone $query)
            ->whereNotNull('effect_id')
            ->selectRaw('effect_id')
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw("SUM({$totalTokenExpr}) as total_tokens")
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->groupBy('effect_id')
            ->orderByDesc('events_count')
            ->get();
        $effectNames = Effect::query()
            ->whereIn('id', $byEffectRaw->pluck('effect_id')->filter()->values()->all())
            ->pluck('name', 'id');
        $byEffect = $byEffectRaw->map(function ($row) use ($effectNames) {
            $effectId = (int) $row->effect_id;
            return [
                'effectId' => $effectId,
                'effectName' => $effectNames->get($effectId) ?? "Effect #{$effectId}",
                'eventsCount' => (int) ($row->events_count ?? 0),
                'totalTokens' => (int) ($row->total_tokens ?? 0),
                'credits' => round((float) ($row->credits ?? 0), 6),
                'costUsdReported' => round((float) ($row->cost_usd_reported ?? 0), 8),
            ];
        })->values();

        $byWorkflowRaw = (clone $query)
            ->whereNotNull('workflow_id')
            ->selectRaw('workflow_id')
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw("SUM({$totalTokenExpr}) as total_tokens")
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->groupBy('workflow_id')
            ->orderByDesc('events_count')
            ->get();
        $workflowNames = Workflow::query()
            ->whereIn('id', $byWorkflowRaw->pluck('workflow_id')->filter()->values()->all())
            ->pluck('name', 'id');
        $byWorkflow = $byWorkflowRaw->map(function ($row) use ($workflowNames) {
            $workflowId = (int) $row->workflow_id;
            return [
                'workflowId' => $workflowId,
                'workflowName' => $workflowNames->get($workflowId) ?? "Workflow #{$workflowId}",
                'eventsCount' => (int) ($row->events_count ?? 0),
                'totalTokens' => (int) ($row->total_tokens ?? 0),
                'credits' => round((float) ($row->credits ?? 0), 6),
                'costUsdReported' => round((float) ($row->cost_usd_reported ?? 0), 8),
            ];
        })->values();

        $byUserRaw = (clone $query)
            ->whereNotNull('user_id')
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw("SUM({$totalTokenExpr}) as total_tokens")
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->groupBy('user_id')
            ->orderByDesc('events_count')
            ->get();
        $users = User::query()
            ->whereIn('id', $byUserRaw->pluck('user_id')->filter()->values()->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $byUser = $byUserRaw->map(function ($row) use ($users) {
            $userId = (int) $row->user_id;
            $user = $users->get($userId);
            return [
                'userId' => $userId,
                'userName' => $user?->name ?? "User #{$userId}",
                'userEmail' => $user?->email,
                'eventsCount' => (int) ($row->events_count ?? 0),
                'totalTokens' => (int) ($row->total_tokens ?? 0),
                'credits' => round((float) ($row->credits ?? 0), 6),
                'costUsdReported' => round((float) ($row->cost_usd_reported ?? 0), 8),
            ];
        })->values();

        $byWorkerRaw = (clone $query)
            ->whereNotNull('worker_id')
            ->where('worker_id', '!=', '')
            ->selectRaw('worker_id')
            ->selectRaw('COUNT(*) as events_count')
            ->selectRaw("SUM({$totalTokenExpr}) as total_tokens")
            ->selectRaw('SUM(COALESCE(credits, 0)) as credits')
            ->selectRaw('SUM(COALESCE(cost_usd_reported, 0)) as cost_usd_reported')
            ->groupBy('worker_id')
            ->orderByDesc('events_count')
            ->get();
        $workerMap = ComfyUiWorker::query()
            ->whereIn('worker_id', $byWorkerRaw->pluck('worker_id')->filter()->values()->all())
            ->get(['worker_id', 'display_name', 'capacity_type', 'instance_type', 'stage'])
            ->keyBy('worker_id');
        $byWorker = $byWorkerRaw->map(function ($row) use ($workerMap) {
            $worker = $workerMap->get($row->worker_id);
            return [
                'workerId' => $row->worker_id,
                'workerName' => $worker?->display_name ?? $row->worker_id,
                'capacityType' => $worker?->capacity_type,
                'instanceType' => $worker?->instance_type,
                'stage' => $worker?->stage,
                'eventsCount' => (int) ($row->events_count ?? 0),
                'totalTokens' => (int) ($row->total_tokens ?? 0),
                'credits' => round((float) ($row->credits ?? 0), 6),
                'costUsdReported' => round((float) ($row->cost_usd_reported ?? 0), 8),
            ];
        })->values();

        return $this->sendResponse([
            'totals' => [
                'eventsCount' => (int) ($totals->events_count ?? 0),
                'inputTokens' => (int) ($totals->input_tokens ?? 0),
                'outputTokens' => (int) ($totals->output_tokens ?? 0),
                'totalTokens' => (int) ($totals->total_tokens ?? 0),
                'credits' => round((float) ($totals->credits ?? 0), 6),
                'costUsdReported' => round((float) ($totals->cost_usd_reported ?? 0), 8),
            ],
            'byProviderNodeModel' => $byProviderNodeModel,
            'byEffect' => $byEffect,
            'byWorkflow' => $byWorkflow,
            'byUser' => $byUser,
            'byWorker' => $byWorker,
        ], 'Partner usage analytics retrieved successfully');
    }
}
