<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\DevNode;
use App\Models\ExecutionEnvironment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DevNodesController extends BaseController
{
    private const ALLOWED_STAGES = ['dev', 'test', 'staging', 'production'];

    public function index(Request $request): JsonResponse
    {
        $query = DevNode::query()
            ->with('executionEnvironment')
            ->orderByDesc('id');

        $status = $request->input('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $stage = $request->input('stage');
        if (is_string($stage) && $stage !== '') {
            $query->where('stage', $this->normalizeStage($stage));
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('aws_instance_id', 'like', "%{$search}%")
                    ->orWhere('instance_type', 'like', "%{$search}%");
            });
        }

        $items = $query->get()
            ->map(fn (DevNode $node) => $this->nodePayload($node))
            ->values();

        return $this->sendResponse(['items' => $items], 'Dev nodes retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $node = DevNode::query()->with('executionEnvironment')->find($id);
        if (!$node) {
            return $this->sendError('Dev node not found.', [], 404);
        }

        return $this->sendResponse($this->nodePayload($node), 'Dev node retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'instance_type' => 'nullable|string|max:64',
            'stage' => 'nullable|string|in:dev,test,staging,production',
            'lifecycle' => 'nullable|string|in:on-demand,spot',
            'status' => 'nullable|string|in:starting,ready,stopping,stopped,error',
            'aws_instance_id' => 'nullable|string|max:128',
            'public_endpoint' => 'nullable|string|max:2048',
            'private_endpoint' => 'nullable|string|max:2048',
            'active_bundle_ref' => 'nullable|string|max:255',
            'assigned_to_user_id' => 'nullable|integer|min:1',
            'metadata_json' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $node = DB::connection('central')->transaction(function () use ($validated) {
            $status = (string) ($validated['status'] ?? 'stopped');
            $node = DevNode::query()->create([
                'name' => $validated['name'],
                'instance_type' => $validated['instance_type'] ?? null,
                'stage' => $this->normalizeStage((string) ($validated['stage'] ?? 'dev')),
                'lifecycle' => $validated['lifecycle'] ?? 'on-demand',
                'status' => $status,
                'aws_instance_id' => $validated['aws_instance_id'] ?? null,
                'public_endpoint' => $validated['public_endpoint'] ?? null,
                'private_endpoint' => $validated['private_endpoint'] ?? null,
                'active_bundle_ref' => $validated['active_bundle_ref'] ?? null,
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'started_at' => in_array($status, ['starting', 'ready'], true) ? now() : null,
                'ready_at' => $status === 'ready' ? now() : null,
                'ended_at' => in_array($status, ['stopped', 'error'], true) ? now() : null,
                'last_activity_at' => now(),
                'metadata_json' => $validated['metadata_json'] ?? null,
            ]);

            $this->syncExecutionEnvironment($node);

            return $node->fresh('executionEnvironment');
        });

        return $this->sendResponse(
            $this->nodePayload($node),
            'Dev node created successfully',
            [],
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $node = DevNode::query()->find($id);
        if (!$node) {
            return $this->sendError('Dev node not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'instance_type' => 'sometimes|nullable|string|max:64',
            'stage' => 'sometimes|string|in:dev,test,staging,production',
            'lifecycle' => 'sometimes|string|in:on-demand,spot',
            'status' => 'sometimes|string|in:starting,ready,stopping,stopped,error',
            'aws_instance_id' => 'sometimes|nullable|string|max:128',
            'public_endpoint' => 'sometimes|nullable|string|max:2048',
            'private_endpoint' => 'sometimes|nullable|string|max:2048',
            'active_bundle_ref' => 'sometimes|nullable|string|max:255',
            'assigned_to_user_id' => 'sometimes|nullable|integer|min:1',
            'last_activity_at' => 'sometimes|nullable|date',
            'metadata_json' => 'sometimes|nullable|array',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        DB::connection('central')->transaction(function () use ($node, $validated): void {
            $previousStatus = (string) $node->status;

            if (array_key_exists('name', $validated)) {
                $node->name = $validated['name'];
            }
            if (array_key_exists('instance_type', $validated)) {
                $node->instance_type = $validated['instance_type'];
            }
            if (array_key_exists('stage', $validated)) {
                $node->stage = $this->normalizeStage((string) $validated['stage']);
            }
            if (array_key_exists('lifecycle', $validated)) {
                $node->lifecycle = $validated['lifecycle'];
            }
            if (array_key_exists('status', $validated)) {
                $node->status = $validated['status'];
            }
            if (array_key_exists('aws_instance_id', $validated)) {
                $node->aws_instance_id = $validated['aws_instance_id'];
            }
            if (array_key_exists('public_endpoint', $validated)) {
                $node->public_endpoint = $validated['public_endpoint'];
            }
            if (array_key_exists('private_endpoint', $validated)) {
                $node->private_endpoint = $validated['private_endpoint'];
            }
            if (array_key_exists('active_bundle_ref', $validated)) {
                $node->active_bundle_ref = $validated['active_bundle_ref'];
            }
            if (array_key_exists('assigned_to_user_id', $validated)) {
                $node->assigned_to_user_id = $validated['assigned_to_user_id'];
            }
            if (array_key_exists('metadata_json', $validated)) {
                $node->metadata_json = $validated['metadata_json'];
            }
            if (array_key_exists('last_activity_at', $validated)) {
                $node->last_activity_at = $validated['last_activity_at']
                    ? Carbon::parse((string) $validated['last_activity_at'])
                    : null;
            } elseif ($node->assigned_to_user_id) {
                $node->last_activity_at = now();
            }

            $this->applyLifecycleTimestamps($node, $previousStatus);
            $node->save();

            $this->syncExecutionEnvironment($node);
        });

        $node->refresh()->load('executionEnvironment');

        return $this->sendResponse($this->nodePayload($node), 'Dev node updated successfully');
    }

    private function normalizeStage(string $stage): string
    {
        $normalized = strtolower(trim($stage));
        if (!in_array($normalized, self::ALLOWED_STAGES, true)) {
            return 'dev';
        }

        // Keep legacy compatibility while moving the studio semantics toward test stage.
        return $normalized === 'staging' ? 'test' : $normalized;
    }

    private function applyLifecycleTimestamps(DevNode $node, string $previousStatus): void
    {
        if ($node->status === $previousStatus) {
            return;
        }

        if (in_array($node->status, ['starting', 'ready'], true) && !$node->started_at) {
            $node->started_at = now();
        }

        if ($node->status === 'ready' && !$node->ready_at) {
            $node->ready_at = now();
        }

        if (in_array($node->status, ['stopped', 'error'], true) && !$node->ended_at) {
            $node->ended_at = now();
        }

        if (in_array($node->status, ['starting', 'ready'], true)) {
            $node->ended_at = null;
        }

        $node->last_activity_at = now();
    }

    private function syncExecutionEnvironment(DevNode $node): void
    {
        $environment = ExecutionEnvironment::query()
            ->where('kind', 'dev_node')
            ->where('dev_node_id', $node->id)
            ->first();

        $payload = [
            'name' => 'Dev Node - ' . $node->name,
            'kind' => 'dev_node',
            'stage' => $node->stage,
            'fleet_slug' => null,
            'dev_node_id' => $node->id,
            'configuration_json' => [
                'instance_type' => $node->instance_type,
                'lifecycle' => $node->lifecycle,
                'active_bundle_ref' => $node->active_bundle_ref,
            ],
            'is_active' => !in_array((string) $node->status, ['stopped', 'error'], true),
        ];

        if ($environment) {
            $environment->fill($payload);
            $environment->save();
        } else {
            $environment = ExecutionEnvironment::query()->create($payload);
        }

        $node->setRelation('executionEnvironment', $environment);
    }

    private function nodePayload(DevNode $node): array
    {
        $environment = $node->relationLoaded('executionEnvironment')
            ? $node->executionEnvironment
            : $node->executionEnvironment()->first();

        return [
            'id' => $node->id,
            'name' => $node->name,
            'instance_type' => $node->instance_type,
            'stage' => $node->stage,
            'lifecycle' => $node->lifecycle,
            'status' => $node->status,
            'aws_instance_id' => $node->aws_instance_id,
            'public_endpoint' => $node->public_endpoint,
            'private_endpoint' => $node->private_endpoint,
            'active_bundle_ref' => $node->active_bundle_ref,
            'assigned_to_user_id' => $node->assigned_to_user_id,
            'started_at' => $node->started_at?->toIso8601String(),
            'ready_at' => $node->ready_at?->toIso8601String(),
            'ended_at' => $node->ended_at?->toIso8601String(),
            'last_activity_at' => $node->last_activity_at?->toIso8601String(),
            'metadata_json' => $node->metadata_json,
            'execution_environment' => $environment ? [
                'id' => $environment->id,
                'kind' => $environment->kind,
                'stage' => $environment->stage,
                'is_active' => (bool) $environment->is_active,
            ] : null,
            'created_at' => $this->toIso8601($node->created_at),
            'updated_at' => $this->toIso8601($node->updated_at),
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
