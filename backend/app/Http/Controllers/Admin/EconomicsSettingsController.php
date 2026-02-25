<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\EconomicsSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EconomicsSettingsController extends BaseController
{
    public function index(): JsonResponse
    {
        $settings = EconomicsSetting::query()->first();
        $defaultsApplied = false;

        if (!$settings) {
            $settings = EconomicsSetting::query()->create(EconomicsSetting::defaultAttributes());
            $defaultsApplied = true;
        }

        $payload = $settings->toArray();
        $payload['defaults_applied'] = $defaultsApplied;

        return $this->sendResponse($payload, 'Economics settings retrieved successfully');
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token_usd_rate' => 'required|numeric|min:0',
            'spot_multiplier' => 'nullable|numeric|min:0',
            'instance_type_rates' => 'required|array',
            'instance_type_rates.*' => 'numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $payload = $validator->validated();

        $settings = EconomicsSetting::query()->first();
        if (!$settings) {
            $settings = EconomicsSetting::query()->create(array_merge(EconomicsSetting::defaultAttributes(), $payload));
        } else {
            $settings->fill($payload);
            $settings->save();
        }

        $payload = $settings->toArray();
        $payload['defaults_applied'] = false;

        return $this->sendResponse($payload, 'Economics settings updated successfully');
    }
}
