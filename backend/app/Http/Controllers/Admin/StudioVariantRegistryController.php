<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Services\Variants\VariantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioVariantRegistryController extends BaseController
{
    public function __construct(
        private readonly VariantRegistryService $variantRegistryService
    ) {
    }

    public function eligibleByEffectRevision(Request $request, int $effectRevisionId): JsonResponse
    {
        $stage = (string) $request->input('stage', 'staging');

        try {
            $variants = $this->variantRegistryService->eligibleVariantsForEffectRevision($effectRevisionId, $stage);
        } catch (\Throwable $e) {
            return $this->sendError('Variant registry resolution failed.', [
                'error' => $e->getMessage(),
            ], 422);
        }

        return $this->sendResponse([
            'effect_revision_id' => $effectRevisionId,
            'stage' => strtolower(trim($stage)) === 'production' ? 'production' : 'staging',
            'items' => $variants,
        ], 'Eligible variants resolved successfully.');
    }
}

