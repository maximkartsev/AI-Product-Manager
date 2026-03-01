<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ActionLog;
use App\Services\Observability\ActionLogAnomalyDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioActionLogsController extends BaseController
{
    public function __construct(
        private readonly ActionLogAnomalyDetector $anomalyDetector
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ActionLog::query()->orderByDesc('id');
        if ($request->filled('severity')) {
            $query->where('severity', (string) $request->input('severity'));
        }
        if ($request->filled('module')) {
            $query->where('module', (string) $request->input('module'));
        }
        if ($request->boolean('unresolved_only', false)) {
            $query->whereNull('resolved_at');
        }

        $items = $query->limit(200)->get()->map(fn (ActionLog $log) => [
            'id' => $log->id,
            'event' => $log->event,
            'severity' => $log->severity,
            'module' => $log->module,
            'telemetry_sink' => $log->telemetry_sink,
            'message' => $log->message,
            'economic_impact_json' => $log->economic_impact_json,
            'operator_action_json' => $log->operator_action_json,
            'context_json' => $log->context_json,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'resolved_at' => $log->resolved_at?->toIso8601String(),
            'created_at' => $log->created_at?->toIso8601String(),
            'updated_at' => $log->updated_at?->toIso8601String(),
        ])->values();

        return $this->sendResponse(['items' => $items], 'Action logs retrieved successfully.');
    }

    public function sinks(): JsonResponse
    {
        return $this->sendResponse([
            'sinks' => [
                [
                    'name' => 'tig',
                    'usage' => 'worker_runtime_metrics',
                    'contains_sensitive_economics' => false,
                ],
                [
                    'name' => 'cloudwatch',
                    'usage' => 'aws_native_infrastructure_signals',
                    'contains_sensitive_economics' => false,
                ],
                [
                    'name' => 'admin',
                    'usage' => 'money_hud_and_sensitive_unit_economics',
                    'contains_sensitive_economics' => true,
                ],
            ],
        ], 'Observability sink mapping retrieved successfully.');
    }

    public function scanAnomalies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lookback_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $result = $this->anomalyDetector->scanRecent((int) ($validated['lookback_minutes'] ?? 30));

        return $this->sendResponse($result, 'Action log anomaly scan completed.');
    }
}

