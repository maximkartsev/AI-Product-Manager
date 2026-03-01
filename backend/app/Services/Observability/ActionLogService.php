<?php

namespace App\Services\Observability;

use App\Models\ActionLog;

class ActionLogService
{
    /**
     * @param array<string, mixed>|null $economicImpact
     * @param array<string, mixed>|null $operatorAction
     * @param array<string, mixed>|null $context
     */
    public function log(
        string $severity,
        string $event,
        string $module,
        ?string $message = null,
        ?string $telemetrySink = null,
        ?array $economicImpact = null,
        ?array $operatorAction = null,
        ?array $context = null
    ): ActionLog {
        $normalizedSeverity = $this->normalizeSeverity($severity);
        $impact = $economicImpact;
        $action = $operatorAction;

        if (in_array($normalizedSeverity, ['warn', 'critical'], true)) {
            $impact = $impact ?? ['kind' => 'unknown', 'estimated_usd_delta' => null];
            $action = $action ?? ['instruction' => 'Review related benchmark and routing signals.'];
        }

        return ActionLog::query()->create([
            'severity' => $normalizedSeverity,
            'event' => $event,
            'module' => $module,
            'telemetry_sink' => $telemetrySink,
            'message' => $message,
            'economic_impact_json' => $impact,
            'operator_action_json' => $action,
            'context_json' => $context,
            'occurred_at' => now(),
        ]);
    }

    private function normalizeSeverity(string $severity): string
    {
        $normalized = strtolower(trim($severity));
        if (in_array($normalized, ['debug', 'info', 'warn', 'critical'], true)) {
            return $normalized;
        }

        return 'info';
    }
}

