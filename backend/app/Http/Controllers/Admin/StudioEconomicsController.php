<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Services\RunCostModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudioEconomicsController extends BaseController
{
    public function costModel(Request $request, RunCostModelService $costModel): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'startup_seconds' => 'nullable|numeric|min:0',
            'busy_seconds_per_run' => 'required|numeric|min:0',
            'idle_seconds_after_batch' => 'nullable|numeric|min:0',
            'compute_rate_usd_per_second' => 'required|numeric|min:0',
            'partner_cost_usd_per_run' => 'nullable|numeric|min:0',
            'revenue_usd_per_run' => 'nullable|numeric|min:0',
            'run_counts' => 'nullable|array|min:1|max:10',
            'run_counts.*' => 'integer|min:1|max:100000',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $result = $costModel->build($validator->validated());

        return $this->sendResponse($result, 'Studio cost model calculated successfully');
    }
}
