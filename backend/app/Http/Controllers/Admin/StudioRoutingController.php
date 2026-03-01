<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Services\Observability\ActionLogService;
use App\Services\Variants\RoutingBindingService;
use App\Services\Variants\VariantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudioRoutingController extends BaseController
{
    public function __construct(
        private readonly RoutingBindingService $routingBindingService,
        private readonly VariantRegistryService $variantRegistryService,
        private readonly ActionLogService $actionLogService
    ) {
    }

    public function show(int $effectRevisionId): JsonResponse
    {
        $binding = $this->routingBindingService->activeForEffectRevision($effectRevisionId);

        return $this->sendResponse([
            'effect_revision_id' => $effectRevisionId,
            'active_binding' => $binding,
        ], 'Routing binding retrieved successfully.');
    }

    public function apply(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'variant_id' => 'required|string|max:255',
            'stage' => 'nullable|string|in:staging,production',
            'reason' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $variants = $this->variantRegistryService->eligibleVariantsForEffectRevision(
            (int) $validated['effect_revision_id'],
            (string) ($validated['stage'] ?? 'staging')
        );
        $variant = collect($variants)->firstWhere('variant_id', (string) $validated['variant_id']);
        if (!$variant) {
            return $this->sendError('Variant is not eligible for this effect revision.', [], 422);
        }

        try {
            $binding = $this->routingBindingService->applyVariant(
                effectRevisionId: (int) $validated['effect_revision_id'],
                variant: $variant,
                userId: $request->user()?->id ? (int) $request->user()->id : null,
                reason: isset($validated['reason']) ? ['message' => $validated['reason']] : null
            );
        } catch (\Throwable $e) {
            return $this->sendError('Routing apply failed.', ['error' => $e->getMessage()], 422);
        }
        $this->actionLogService->log(
            severity: 'info',
            event: 'routing_binding_applied',
            module: 'routing',
            message: 'Routing binding applied to effect revision.',
            telemetrySink: 'admin',
            context: [
                'effect_revision_id' => (int) $validated['effect_revision_id'],
                'variant_id' => (string) $validated['variant_id'],
            ]
        );

        return $this->sendResponse($binding, 'Routing binding applied successfully.');
    }

    public function rollback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'effect_revision_id' => 'required|integer|min:1|exists:effect_revisions,id',
            'reason' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }
        $validated = $validator->validated();

        try {
            $binding = $this->routingBindingService->rollback(
                effectRevisionId: (int) $validated['effect_revision_id'],
                userId: $request->user()?->id ? (int) $request->user()->id : null,
                reason: isset($validated['reason']) ? ['message' => $validated['reason']] : null
            );
        } catch (\Throwable $e) {
            return $this->sendError('Routing rollback failed.', ['error' => $e->getMessage()], 422);
        }
        $this->actionLogService->log(
            severity: 'warn',
            event: 'routing_binding_rolled_back',
            module: 'routing',
            message: 'Routing binding rolled back.',
            telemetrySink: 'admin',
            economicImpact: ['kind' => 'routing_change', 'estimated_usd_delta' => null],
            operatorAction: ['instruction' => 'Confirm post-rollback quality and economics in Money HUD.'],
            context: ['effect_revision_id' => (int) $validated['effect_revision_id']]
        );

        return $this->sendResponse([
            'effect_revision_id' => (int) $validated['effect_revision_id'],
            'active_binding' => $binding,
        ], 'Routing rollback completed.');
    }
}

