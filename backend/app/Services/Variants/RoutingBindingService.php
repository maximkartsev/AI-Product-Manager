<?php

namespace App\Services\Variants;

use App\Models\EffectRevision;
use App\Models\EffectVariantBinding;
use Illuminate\Support\Facades\DB;

class RoutingBindingService
{
    public function activeForEffectRevision(int $effectRevisionId): ?EffectVariantBinding
    {
        return EffectVariantBinding::query()
            ->where('effect_revision_id', $effectRevisionId)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $variant
     * @param array<string, mixed>|null $reason
     */
    public function applyVariant(
        int $effectRevisionId,
        array $variant,
        ?int $userId = null,
        ?array $reason = null
    ): EffectVariantBinding {
        $revision = EffectRevision::query()->find($effectRevisionId);
        if (!$revision) {
            throw new \RuntimeException('Effect revision not found.');
        }

        return DB::connection('central')->transaction(function () use ($revision, $variant, $userId, $reason) {
            EffectVariantBinding::query()
                ->where('effect_revision_id', $revision->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return EffectVariantBinding::query()->create([
                'effect_id' => (int) $revision->effect_id,
                'effect_revision_id' => $revision->id,
                'variant_id' => (string) ($variant['variant_id'] ?? ''),
                'workflow_id' => $variant['workflow_id'] ?? null,
                'execution_environment_id' => $variant['execution_environment_id'] ?? null,
                'stage' => (string) ($variant['stage'] ?? 'production'),
                'is_active' => true,
                'rollback_of_binding_id' => null,
                'reason_json' => $reason,
                'created_by_user_id' => $userId,
                'applied_at' => now(),
            ]);
        });
    }

    /**
     * @param array<string, mixed>|null $reason
     */
    public function rollback(int $effectRevisionId, ?int $userId = null, ?array $reason = null): ?EffectVariantBinding
    {
        return DB::connection('central')->transaction(function () use ($effectRevisionId, $userId, $reason) {
            $current = EffectVariantBinding::query()
                ->where('effect_revision_id', $effectRevisionId)
                ->where('is_active', true)
                ->latest('id')
                ->first();
            if (!$current) {
                return null;
            }

            $current->is_active = false;
            $current->save();

            $previous = EffectVariantBinding::query()
                ->where('effect_revision_id', $effectRevisionId)
                ->where('id', '<', $current->id)
                ->orderByDesc('id')
                ->first();
            if (!$previous) {
                return null;
            }

            return EffectVariantBinding::query()->create([
                'effect_id' => $previous->effect_id,
                'effect_revision_id' => $previous->effect_revision_id,
                'variant_id' => $previous->variant_id,
                'workflow_id' => $previous->workflow_id,
                'execution_environment_id' => $previous->execution_environment_id,
                'stage' => $previous->stage,
                'is_active' => true,
                'rollback_of_binding_id' => $current->id,
                'reason_json' => $reason,
                'created_by_user_id' => $userId,
                'applied_at' => now(),
            ]);
        });
    }
}

