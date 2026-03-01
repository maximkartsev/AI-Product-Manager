<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\AiJobDispatch;
use App\Models\LoadTestRun;
use App\Services\LoadTest\EcsRunTaskService;
use App\Services\LoadTesting\LoadTestRunnerService;
use App\Services\Observability\ActionLogService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudioLoadTestRunsController extends BaseController
{
    public function __construct(
        private readonly EcsRunTaskService $taskLauncher,
        private readonly LoadTestRunnerService $runnerService,
        private readonly ActionLogService $actionLogService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = LoadTestRun::query()->orderByDesc('id');

        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $items = $query->get()
            ->map(fn (LoadTestRun $item) => $this->payload($item))
            ->values();

        return $this->sendResponse(['items' => $items], 'Load test runs retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $item = LoadTestRun::query()->find($id);
        if (!$item) {
            return $this->sendError('Load test run not found.', [], 404);
        }

        return $this->sendResponse($this->payload($item), 'Load test run retrieved successfully');
    }

    public function status(int $id): JsonResponse
    {
        $item = LoadTestRun::query()->find($id);
        if (!$item) {
            return $this->sendError('Load test run not found.', [], 404);
        }

        $dispatchStats = AiJobDispatch::query()
            ->where('load_test_run_id', $item->id)
            ->selectRaw('
                COUNT(*) as total_dispatches,
                SUM(CASE WHEN status = "queued" THEN 1 ELSE 0 END) as queued_count,
                SUM(CASE WHEN status = "leased" THEN 1 ELSE 0 END) as leased_count,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count
            ')
            ->first();

        $metrics = is_array($item->metrics_json) ? $item->metrics_json : [];
        $submittedFromMetrics = (int) data_get($metrics, 'total_submitted_dispatches', 0);
        $submitted = max(
            $submittedFromMetrics,
            (int) ($dispatchStats->total_dispatches ?? 0)
        );

        $faultEvents = data_get($metrics, 'fault_events', []);
        if (!is_array($faultEvents) || empty($faultEvents)) {
            $stageReports = data_get($metrics, 'stages', []);
            if (is_array($stageReports)) {
                $faultEvents = collect($stageReports)
                    ->map(function (mixed $stage): ?array {
                        if (!is_array($stage)) {
                            return null;
                        }
                        $fault = $stage['fault'] ?? null;
                        if (!is_array($fault)) {
                            return null;
                        }

                        return [
                            'stage_id' => $stage['stage_id'] ?? null,
                            'stage_order' => $stage['stage_order'] ?? null,
                            'status' => $fault['status'] ?? 'unknown',
                            'fault_method' => $fault['fault_method'] ?? null,
                            'fis_experiment_arn' => $fault['experiment_arn'] ?? null,
                            'target_instance_ids' => $fault['target_instance_ids'] ?? [],
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        return $this->sendResponse([
            'id' => $item->id,
            'status' => $item->status,
            'started_at' => $this->toIso8601($item->started_at),
            'completed_at' => $this->toIso8601($item->completed_at),
            'submitted_count' => $submitted,
            'queued_count' => (int) ($dispatchStats->queued_count ?? 0),
            'leased_count' => (int) ($dispatchStats->leased_count ?? 0),
            'completed_count' => (int) ($dispatchStats->completed_count ?? 0),
            'failed_count' => (int) ($dispatchStats->failed_count ?? 0),
            'success_count' => (int) ($item->success_count ?? 0),
            'failure_count' => (int) ($item->failure_count ?? 0),
            'achieved_rps' => $item->achieved_rps,
            'achieved_rpm' => $item->achieved_rpm,
            'p95_latency_ms' => $item->p95_latency_ms,
            'queue_wait_p95_seconds' => $item->queue_wait_p95_seconds,
            'processing_p95_seconds' => $item->processing_p95_seconds,
            'fault_events' => $faultEvents,
            'ecs_task_arn' => data_get($metrics, 'ecs_task_arn', data_get($metrics, 'runner.launch.task_arn')),
        ], 'Load test run status retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'load_test_scenario_id' => 'required|integer|min:1|exists:load_test_scenarios,id',
            'execution_environment_id' => 'required|integer|min:1|exists:execution_environments,id',
            'effect_revision_id' => 'nullable|integer|min:1|exists:effect_revisions,id',
            'experiment_variant_id' => 'nullable|integer|min:1|exists:experiment_variants,id',
            'fleet_config_snapshot_start_id' => 'nullable|integer|min:1|exists:fleet_config_snapshots,id',
            'fleet_config_snapshot_end_id' => 'nullable|integer|min:1|exists:fleet_config_snapshots,id',
            'status' => 'nullable|string|in:queued,running,completed,failed,cancelled',
            'achieved_rpm' => 'nullable|numeric|min:0',
            'achieved_rps' => 'nullable|numeric|min:0',
            'success_count' => 'nullable|integer|min:0',
            'failure_count' => 'nullable|integer|min:0',
            'p95_latency_ms' => 'nullable|numeric|min:0',
            'queue_wait_p95_seconds' => 'nullable|numeric|min:0',
            'processing_p95_seconds' => 'nullable|numeric|min:0',
            'compute_cost_usd' => 'nullable|numeric|min:0',
            'effective_cost_usd' => 'nullable|numeric|min:0',
            'partner_cost_usd' => 'nullable|numeric|min:0',
            'margin_usd' => 'nullable|numeric',
            'metrics_json' => 'nullable|array',
            'input_file_id' => 'nullable|integer|min:1',
            'input_payload' => 'nullable|array',
            'benchmark_context_id' => 'nullable|string|max:120',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $validated['status'] = $validated['status'] ?? 'queued';
        $validated['created_by_user_id'] = $request->user()?->id ? (int) $request->user()->id : null;
        $metricsJson = is_array($validated['metrics_json'] ?? null) ? $validated['metrics_json'] : [];
        if ($request->filled('input_file_id')) {
            $metricsJson['input_file_id'] = (int) $request->input('input_file_id');
        }
        if (is_array($request->input('input_payload'))) {
            $metricsJson['input_payload'] = $request->input('input_payload');
        }
        if ($request->filled('benchmark_context_id')) {
            $metricsJson['benchmark_context_id'] = (string) $request->input('benchmark_context_id');
        }
        $validated['metrics_json'] = $metricsJson;

        $item = DB::connection('central')->transaction(function () use ($validated) {
            return LoadTestRun::query()->create($validated);
        });

        return $this->sendResponse($this->payload($item), 'Load test run created successfully', [], 201);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $item = LoadTestRun::query()->find($id);
        if (!$item) {
            return $this->sendError('Load test run not found.', [], 404);
        }
        if (in_array((string) $item->status, ['running', 'completed', 'failed', 'cancelled'], true)) {
            return $this->sendError('Load test run cannot be started from current status.', [], 422);
        }

        $metrics = is_array($item->metrics_json) ? $item->metrics_json : [];
        if ($request->filled('input_file_id')) {
            $metrics['input_file_id'] = (int) $request->input('input_file_id');
        }
        if (is_array($request->input('input_payload'))) {
            $metrics['input_payload'] = $request->input('input_payload');
        }
        if ($request->filled('benchmark_context_id')) {
            $metrics['benchmark_context_id'] = (string) $request->input('benchmark_context_id');
        }

        if ((int) data_get($metrics, 'input_file_id', 0) <= 0) {
            return $this->sendError('Load test run requires input_file_id before execution.', [], 422);
        }
        if ((int) ($item->effect_revision_id ?? 0) <= 0 || (int) ($item->execution_environment_id ?? 0) <= 0) {
            return $this->sendError('Load test run requires effect_revision_id and execution_environment_id.', [], 422);
        }

        $item->status = 'running';
        $item->started_at = $item->started_at ?: now();
        $item->completed_at = null;
        $item->metrics_json = $metrics;
        $item->save();

        $mode = (string) $request->input('mode', 'ecs');
        if (!in_array($mode, ['ecs', 'inline'], true)) {
            $mode = 'ecs';
        }

        if ($mode === 'inline') {
            $result = $this->runnerService->run($item, false);
            $this->actionLogService->log(
                severity: 'info',
                event: 'load_test_run_started_inline',
                module: 'scenario_executor',
                message: 'Load test run executed inline by operator request.',
                telemetrySink: 'admin',
                context: ['load_test_run_id' => $item->id]
            );
            return $this->sendResponse([
                'run' => $this->payload($item->fresh()),
                'execution' => array_merge($result, ['mode' => 'inline']),
            ], 'Load test run executed inline successfully.');
        }

        $launch = $this->taskLauncher->launch($item);
        $metrics = is_array($item->metrics_json) ? $item->metrics_json : [];
        $metrics['runner'] = [
            'mode' => 'ecs',
            'launch' => $launch,
            'launched_at' => now()->toIso8601String(),
        ];
        if ($launch['launched'] ?? false) {
            $metrics['ecs_task_arn'] = $launch['task_arn'] ?? null;
        }
        $item->metrics_json = $metrics;

        if (!($launch['launched'] ?? false)) {
            if ($request->boolean('allow_inline_fallback', true)) {
                $result = $this->runnerService->run($item, false);
                $item = $item->fresh();
                $fallbackMetrics = is_array($item->metrics_json) ? $item->metrics_json : [];
                $fallbackMetrics['runner']['fallback_execution'] = $result;
                $item->metrics_json = $fallbackMetrics;
                $item->save();
                $this->actionLogService->log(
                    severity: 'warn',
                    event: 'load_test_runner_ecs_fallback',
                    module: 'scenario_executor',
                    message: 'ECS launch failed; inline fallback executed.',
                    telemetrySink: 'admin',
                    economicImpact: ['kind' => 'latency', 'estimated_usd_delta' => null],
                    operatorAction: ['instruction' => 'Inspect ECS task configuration and network bindings.'],
                    context: ['load_test_run_id' => $item->id, 'launch' => $launch]
                );

                return $this->sendResponse([
                    'run' => $this->payload($item->fresh()),
                    'execution' => array_merge($result, ['mode' => 'inline_fallback']),
                ], 'Load test runner ECS launch failed; executed inline fallback.');
            }

            $item->status = 'failed';
            $item->completed_at = now();
        }

        $item->save();
        if ($launch['launched'] ?? false) {
            $this->actionLogService->log(
                severity: 'info',
                event: 'load_test_runner_ecs_launched',
                module: 'scenario_executor',
                message: 'Load test runner ECS task launched.',
                telemetrySink: 'cloudwatch',
                context: ['load_test_run_id' => $item->id, 'task_arn' => $launch['task_arn'] ?? null]
            );
        }

        return $this->sendResponse([
            'run' => $this->payload($item->fresh()),
            'launch' => $launch,
        ], 'Load test run start requested successfully.');
    }

    public function cancel(int $id): JsonResponse
    {
        $item = LoadTestRun::query()->find($id);
        if (!$item) {
            return $this->sendError('Load test run not found.', [], 404);
        }

        if ((string) $item->status === 'cancelled') {
            return $this->sendResponse(
                $this->payload($item),
                'Load test run already cancelled.'
            );
        }

        if (in_array((string) $item->status, ['completed', 'failed'], true)) {
            return $this->sendResponse(
                $this->payload($item),
                'Load test run already completed and cannot be cancelled.'
            );
        }

        $metrics = is_array($item->metrics_json) ? $item->metrics_json : [];
        $taskArn = data_get($metrics, 'ecs_task_arn', data_get($metrics, 'runner.launch.task_arn'));
        $stopResult = $this->taskLauncher->stop(
            is_string($taskArn) ? $taskArn : null,
            'load_test_cancelled'
        );

        $metrics['runner']['stop'] = $stopResult;
        $metrics['runner']['cancelled_at'] = now()->toIso8601String();

        $item->status = 'cancelled';
        $item->completed_at = now();
        $item->metrics_json = $metrics;
        $item->save();
        $this->actionLogService->log(
            severity: 'warn',
            event: 'load_test_run_cancelled',
            module: 'scenario_executor',
            message: 'Load test run cancelled by operator.',
            telemetrySink: 'admin',
            economicImpact: ['kind' => 'wasted_compute', 'estimated_usd_delta' => null],
            operatorAction: ['instruction' => 'Review cancellation reason and rerun if necessary.'],
            context: ['load_test_run_id' => $item->id, 'stop' => $stopResult]
        );

        return $this->sendResponse($this->payload($item->fresh()), 'Load test run cancelled successfully.');
    }

    private function payload(LoadTestRun $item): array
    {
        return [
            'id' => $item->id,
            'load_test_scenario_id' => $item->load_test_scenario_id,
            'execution_environment_id' => $item->execution_environment_id,
            'effect_revision_id' => $item->effect_revision_id,
            'experiment_variant_id' => $item->experiment_variant_id,
            'fleet_config_snapshot_start_id' => $item->fleet_config_snapshot_start_id,
            'fleet_config_snapshot_end_id' => $item->fleet_config_snapshot_end_id,
            'status' => $item->status,
            'achieved_rpm' => $item->achieved_rpm,
            'achieved_rps' => $item->achieved_rps,
            'success_count' => $item->success_count,
            'failure_count' => $item->failure_count,
            'p95_latency_ms' => $item->p95_latency_ms,
            'queue_wait_p95_seconds' => $item->queue_wait_p95_seconds,
            'processing_p95_seconds' => $item->processing_p95_seconds,
            'compute_cost_usd' => $item->compute_cost_usd,
            'effective_cost_usd' => $item->effective_cost_usd,
            'partner_cost_usd' => $item->partner_cost_usd,
            'margin_usd' => $item->margin_usd,
            'metrics_json' => $item->metrics_json,
            'started_at' => $this->toIso8601($item->started_at),
            'completed_at' => $this->toIso8601($item->completed_at),
            'created_by_user_id' => $item->created_by_user_id,
            'created_at' => $this->toIso8601($item->created_at),
            'updated_at' => $this->toIso8601($item->updated_at),
        ];
    }

    private function toIso8601(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable $e) {
                return $value;
            }
        }

        return null;
    }
}
