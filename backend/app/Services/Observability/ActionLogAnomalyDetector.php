<?php

namespace App\Services\Observability;

use App\Models\ActionLog;
use Illuminate\Support\Facades\Http;

class ActionLogAnomalyDetector
{
    public function __construct(
        private readonly ActionLogService $actionLogService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function scanRecent(int $lookbackMinutes = 30): array
    {
        $logs = ActionLog::query()
            ->where('occurred_at', '>=', now()->subMinutes($lookbackMinutes))
            ->orderByDesc('id')
            ->limit(200)
            ->get(['event', 'severity', 'module', 'message', 'economic_impact_json', 'context_json', 'occurred_at']);

        if ($logs->isEmpty()) {
            return ['anomalies' => [], 'reason' => 'no_logs'];
        }

        $warnOrCriticalCount = $logs->whereIn('severity', ['warn', 'critical'])->count();
        $moduleCounts = $logs->groupBy('module')->map(fn ($items) => $items->count())->toArray();
        arsort($moduleCounts);
        $dominantModule = array_key_first($moduleCounts);
        $dominantCount = $dominantModule ? (int) ($moduleCounts[$dominantModule] ?? 0) : 0;

        $anomalies = [];
        if ($warnOrCriticalCount >= 5) {
            $anomalies[] = [
                'kind' => 'warn_spike',
                'warn_or_critical_count' => $warnOrCriticalCount,
                'lookback_minutes' => $lookbackMinutes,
            ];
        }
        if ($dominantModule && $dominantCount >= 10) {
            $anomalies[] = [
                'kind' => 'module_event_concentration',
                'module' => $dominantModule,
                'count' => $dominantCount,
                'lookback_minutes' => $lookbackMinutes,
            ];
        }

        $aiConclusion = $this->geminiConclusion($logs->toArray());
        if (is_array($aiConclusion) && (($aiConclusion['is_anomaly'] ?? false) === true)) {
            $anomalies[] = [
                'kind' => 'ai_detected_anomaly',
                'ai_summary' => $aiConclusion['summary'] ?? null,
                'risk_level' => $aiConclusion['risk_level'] ?? null,
            ];
        }

        if (!empty($anomalies)) {
            $this->actionLogService->log(
                severity: 'critical',
                event: 'action_log_anomaly_detected',
                module: 'observability',
                message: 'Anomaly detector found unusual operational signal patterns.',
                telemetrySink: 'admin',
                economicImpact: ['kind' => 'stability_risk', 'estimated_usd_delta' => null],
                operatorAction: ['instruction' => 'Open action logs and Money HUD, then evaluate routing and capacity controls.'],
                context: ['anomalies' => $anomalies, 'lookback_minutes' => $lookbackMinutes]
            );
        }

        return [
            'anomalies' => $anomalies,
            'warn_or_critical_count' => $warnOrCriticalCount,
            'lookback_minutes' => $lookbackMinutes,
            'dominant_module' => $dominantModule,
            'dominant_module_count' => $dominantCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     * @return array<string, mixed>|null
     */
    private function geminiConclusion(array $logs): ?array
    {
        $apiKey = (string) config('services.comfyui.gemini_api_key');
        if ($apiKey === '') {
            return null;
        }

        $model = (string) config('services.comfyui.gemini_model', 'gemini-2.0-flash');
        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $model
        );
        $payload = json_encode($logs, JSON_UNESCAPED_SLASHES);
        $prompt = "Analyze action logs for operational anomalies. Return JSON only: {\"is_anomaly\":bool,\"risk_level\":\"low|medium|high\",\"summary\":\"...\"}\nLOGS={$payload}";

        try {
            $response = Http::timeout(20)->post($endpoint . '?key=' . urlencode($apiKey), [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
            ]);
            if (!$response->successful()) {
                return null;
            }
            $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
            if ($text === '') {
                return null;
            }
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if (preg_match('/\{[\s\S]*\}/', $text, $matches) !== 1) {
                return null;
            }
            $decoded = json_decode((string) $matches[0], true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

