<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\PartnerUsagePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PartnerUsagePricingController extends BaseController
{
    public function index(): JsonResponse
    {
        $items = PartnerUsagePrice::query()
            ->orderBy('provider')
            ->orderBy('node_class_type')
            ->orderBy('model')
            ->get()
            ->map(function (PartnerUsagePrice $row) {
                return [
                    'id' => $row->id,
                    'provider' => $row->provider,
                    'nodeClassType' => $row->node_class_type,
                    'model' => $row->model !== '' ? $row->model : null,
                    'usdPer1mInputTokens' => $row->usd_per_1m_input_tokens,
                    'usdPer1mOutputTokens' => $row->usd_per_1m_output_tokens,
                    'usdPer1mTotalTokens' => $row->usd_per_1m_total_tokens,
                    'usdPerCredit' => $row->usd_per_credit,
                    'firstSeenAt' => $row->first_seen_at?->toIso8601String(),
                    'lastSeenAt' => $row->last_seen_at?->toIso8601String(),
                    'sampleUiJson' => $row->sample_ui_json,
                    'createdAt' => $row->created_at?->toIso8601String(),
                    'updatedAt' => $row->updated_at?->toIso8601String(),
                ];
            })
            ->values();

        return $this->sendResponse(['items' => $items], 'Partner pricing retrieved successfully');
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.provider' => 'required|string|max:100',
            'items.*.nodeClassType' => 'required|string|max:255',
            'items.*.model' => 'nullable|string|max:255',
            'items.*.usdPer1mInputTokens' => 'nullable|numeric|min:0',
            'items.*.usdPer1mOutputTokens' => 'nullable|numeric|min:0',
            'items.*.usdPer1mTotalTokens' => 'nullable|numeric|min:0',
            'items.*.usdPerCredit' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $items = $validator->validated()['items'];
        $now = now();
        $rows = [];
        foreach ($items as $item) {
            $provider = strtolower(trim((string) ($item['provider'] ?? '')));
            $nodeClassType = trim((string) ($item['nodeClassType'] ?? ''));
            if ($provider === '' || $nodeClassType === '') {
                continue;
            }

            $model = trim((string) ($item['model'] ?? ''));
            $rows[] = [
                'provider' => $provider,
                'node_class_type' => $nodeClassType,
                'model' => $model,
                'usd_per_1m_input_tokens' => isset($item['usdPer1mInputTokens'])
                    ? (float) $item['usdPer1mInputTokens']
                    : null,
                'usd_per_1m_output_tokens' => isset($item['usdPer1mOutputTokens'])
                    ? (float) $item['usdPer1mOutputTokens']
                    : null,
                'usd_per_1m_total_tokens' => isset($item['usdPer1mTotalTokens'])
                    ? (float) $item['usdPer1mTotalTokens']
                    : null,
                'usd_per_credit' => isset($item['usdPerCredit'])
                    ? (float) $item['usdPerCredit']
                    : null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return $this->sendError('No valid pricing rows to update.', [], 422);
        }

        PartnerUsagePrice::query()->upsert(
            $rows,
            ['provider', 'node_class_type', 'model'],
            [
                'usd_per_1m_input_tokens',
                'usd_per_1m_output_tokens',
                'usd_per_1m_total_tokens',
                'usd_per_credit',
                'updated_at',
            ]
        );

        return $this->index();
    }
}
