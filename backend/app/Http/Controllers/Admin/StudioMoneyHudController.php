<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Services\Economics\StudioMoneyHudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudioMoneyHudController extends BaseController
{
    public function __construct(
        private readonly StudioMoneyHudService $moneyHudService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'benchmark_matrix_run_id' => 'required|integer|min:1|exists:benchmark_matrix_runs,id',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        try {
            $payload = $this->moneyHudService->buildForBenchmarkRun((int) $request->input('benchmark_matrix_run_id'));
        } catch (\Throwable $e) {
            return $this->sendError('Money HUD generation failed.', [
                'error' => $e->getMessage(),
            ], 422);
        }

        return $this->sendResponse($payload, 'Money HUD generated successfully.');
    }
}

