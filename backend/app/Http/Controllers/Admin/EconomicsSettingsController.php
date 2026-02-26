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

        $payload = $this->responsePayload($settings);
        $payload['defaults_applied'] = $defaultsApplied;

        return $this->sendResponse($payload, 'Economics settings retrieved successfully');
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->all();
        if (isset($payload['instance_type_rates']) && is_array($payload['instance_type_rates'])) {
            $payload['instance_type_rates'] = $this->flattenInstanceTypeRates($payload['instance_type_rates']);
        }

        $validator = Validator::make($payload, [
            'token_usd_rate' => 'required|numeric|min:0',
            'spot_multiplier' => 'nullable|numeric|min:0',
            'instance_type_rates' => 'required|array',
            'instance_type_rates.*' => 'numeric|min:0',
        ]);
        $validated = $validator->validate();

        $settings = EconomicsSetting::query()->first();
        if (!$settings) {
            $settings = EconomicsSetting::query()->create(array_merge(EconomicsSetting::defaultAttributes(), $validated));
        } else {
            $settings->fill($validated);
            $settings->save();
        }

        $payload = $this->responsePayload($settings);
        $payload['defaults_applied'] = false;

        return $this->sendResponse($payload, 'Economics settings updated successfully');
    }

    private function responsePayload(EconomicsSetting $settings): array
    {
        $payload = $settings->toArray();
        $rates = is_array($settings->instance_type_rates) ? $settings->instance_type_rates : [];
        $payload['instance_type_rates'] = $this->nestInstanceTypeRates($rates);

        return $payload;
    }

    /**
     * @param array<string, mixed> $rates
     * @return array<string, mixed>
     */
    private function flattenInstanceTypeRates(array $rates, string $prefix = ''): array
    {
        $flat = [];
        foreach ($rates as $key => $value) {
            $segment = trim((string) $key);
            if ($segment === '') {
                continue;
            }

            $path = $prefix === '' ? $segment : "{$prefix}.{$segment}";
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flattenInstanceTypeRates($value, $path));
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $rates
     * @return array<string, mixed>
     */
    private function nestInstanceTypeRates(array $rates): array
    {
        $nested = [];
        foreach ($rates as $key => $value) {
            $path = trim((string) $key);
            if ($path === '') {
                continue;
            }

            $segments = array_values(array_filter(explode('.', $path), static fn (string $part): bool => $part !== ''));
            if ($segments === []) {
                continue;
            }

            $cursor = &$nested;
            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    $cursor[$segment] = is_numeric($value) ? (float) $value : $value;
                    continue;
                }

                if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }
                $cursor = &$cursor[$segment];
            }
            unset($cursor);
        }

        return $nested;
    }
}
