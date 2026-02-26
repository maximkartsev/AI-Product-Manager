<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\LoadTestScenario;
use App\Models\LoadTestStage;
use App\Services\AwsInterruptionSignalCatalog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LoadTestScenariosController extends BaseController
{
    public function index(): JsonResponse
    {
        $items = LoadTestScenario::query()
            ->with('stages')
            ->orderByDesc('id')
            ->get()
            ->map(fn (LoadTestScenario $scenario) => $this->scenarioPayload($scenario))
            ->values();

        return $this->sendResponse([
            'items' => $items,
            'supported_aws_signals' => AwsInterruptionSignalCatalog::supportedSignals(),
        ], 'Load test scenarios retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $scenario = LoadTestScenario::query()->with('stages')->find($id);
        if (!$scenario) {
            return $this->sendError('Load test scenario not found.', [], 404);
        }

        return $this->sendResponse([
            'item' => $this->scenarioPayload($scenario),
            'supported_aws_signals' => AwsInterruptionSignalCatalog::supportedSignals(),
        ], 'Load test scenario retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        $scenario = DB::connection('central')->transaction(function () use ($validated, $request) {
            $scenario = LoadTestScenario::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'created_by_user_id' => $request->user()?->id,
            ]);

            $this->replaceStages($scenario, $validated['stages'] ?? []);

            return $scenario->fresh('stages');
        });

        return $this->sendResponse($this->scenarioPayload($scenario), 'Load test scenario created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $scenario = LoadTestScenario::query()->find($id);
        if (!$scenario) {
            return $this->sendError('Load test scenario not found.', [], 404);
        }

        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }
        $validated = $validator->validated();

        DB::connection('central')->transaction(function () use ($scenario, $validated) {
            if (array_key_exists('name', $validated)) {
                $scenario->name = $validated['name'];
            }
            if (array_key_exists('description', $validated)) {
                $scenario->description = $validated['description'];
            }
            if (array_key_exists('is_active', $validated)) {
                $scenario->is_active = (bool) $validated['is_active'];
            }
            $scenario->save();

            if (array_key_exists('stages', $validated)) {
                $this->replaceStages($scenario, $validated['stages'] ?? []);
            }
        });

        return $this->sendResponse(
            $this->scenarioPayload($scenario->fresh('stages')),
            'Load test scenario updated successfully'
        );
    }

    private function replaceStages(LoadTestScenario $scenario, array $stages): void
    {
        LoadTestStage::query()->where('load_test_scenario_id', $scenario->id)->delete();

        foreach ($stages as $idx => $stage) {
            LoadTestStage::query()->create([
                'load_test_scenario_id' => $scenario->id,
                'stage_order' => (int) ($stage['stage_order'] ?? $idx),
                'stage_type' => $stage['stage_type'],
                'duration_seconds' => (int) $stage['duration_seconds'],
                'target_rpm' => array_key_exists('target_rpm', $stage) ? (float) $stage['target_rpm'] : null,
                'target_rps' => array_key_exists('target_rps', $stage) ? (float) $stage['target_rps'] : null,
                'fault_enabled' => (bool) ($stage['fault_enabled'] ?? false),
                'fault_kind' => $stage['fault_kind'] ?? null,
                'fault_interruption_rate' => array_key_exists('fault_interruption_rate', $stage)
                    ? (float) $stage['fault_interruption_rate']
                    : null,
                'fault_target_scope' => $stage['fault_target_scope'] ?? null,
                'fault_method' => $stage['fault_method'] ?? null,
                'fault_notice_seconds' => array_key_exists('fault_notice_seconds', $stage)
                    ? (int) $stage['fault_notice_seconds']
                    : null,
                'economics_spot_discount_override' => array_key_exists('economics_spot_discount_override', $stage)
                    ? (float) $stage['economics_spot_discount_override']
                    : null,
                'config_json' => $stage['config_json'] ?? null,
            ]);
        }
    }

    private function scenarioPayload(LoadTestScenario $scenario): array
    {
        return [
            'id' => $scenario->id,
            'name' => $scenario->name,
            'description' => $scenario->description,
            'is_active' => (bool) $scenario->is_active,
            'created_by_user_id' => $scenario->created_by_user_id,
            'created_at' => $this->toIso8601($scenario->created_at),
            'updated_at' => $this->toIso8601($scenario->updated_at),
            'stages' => $scenario->stages->map(function (LoadTestStage $stage) {
                return [
                    'id' => $stage->id,
                    'stage_order' => $stage->stage_order,
                    'stage_type' => $stage->stage_type,
                    'duration_seconds' => $stage->duration_seconds,
                    'target_rpm' => $stage->target_rpm,
                    'target_rps' => $stage->target_rps,
                    'fault_enabled' => (bool) $stage->fault_enabled,
                    'fault_kind' => $stage->fault_kind,
                    'fault_interruption_rate' => $stage->fault_interruption_rate,
                    'fault_target_scope' => $stage->fault_target_scope,
                    'fault_method' => $stage->fault_method,
                    'fault_notice_seconds' => $stage->fault_notice_seconds,
                    'economics_spot_discount_override' => $stage->economics_spot_discount_override,
                    'config_json' => $stage->config_json,
                ];
            })->values(),
        ];
    }

    private function rules(bool $partial = false): array
    {
        $requiredOrSometimes = $partial ? 'sometimes' : 'required';
        $stagesRule = $partial ? 'sometimes|array|min:1' : 'required|array|min:1';

        return [
            'name' => "{$requiredOrSometimes}|string|max:255",
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'stages' => $stagesRule,
            'stages.*.stage_order' => 'sometimes|integer|min:0',
            'stages.*.stage_type' => 'required|string|in:spike,steady,sine,drop,ramp',
            'stages.*.duration_seconds' => 'required|integer|min:1',
            'stages.*.target_rpm' => 'nullable|numeric|min:0',
            'stages.*.target_rps' => 'nullable|numeric|min:0',
            'stages.*.fault_enabled' => 'sometimes|boolean',
            'stages.*.fault_kind' => 'nullable|string|in:instance_termination',
            'stages.*.fault_interruption_rate' => 'nullable|numeric|min:0|max:1',
            'stages.*.fault_target_scope' => 'nullable|string|in:spot_only,on_demand_only,mixed',
            'stages.*.fault_method' => 'nullable|string|in:fis,asg_terminate',
            'stages.*.fault_notice_seconds' => 'nullable|integer|min:0|max:3600',
            'stages.*.economics_spot_discount_override' => 'nullable|numeric|min:0|max:1',
            'stages.*.config_json' => 'nullable|array',
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
