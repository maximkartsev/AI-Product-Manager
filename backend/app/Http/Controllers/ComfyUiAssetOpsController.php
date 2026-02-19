<?php

namespace App\Http\Controllers;

use App\Models\ComfyUiAssetBundle;
use App\Services\ComfyUiAssetAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class ComfyUiAssetOpsController extends BaseController
{
    public function storeSyncLog(Request $request, ComfyUiAssetAuditService $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bundle_id' => 'string|required|max:64',
            'event' => 'string|required|max:64',
            'notes' => 'string|nullable|max:2000',
            'artifact_s3_key' => 'string|nullable|max:1024',
            'metadata' => 'array|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $bundle = ComfyUiAssetBundle::query()->where('bundle_id', $request->input('bundle_id'))->first();
        if (!$bundle) {
            return $this->sendError('Bundle not found.', [], 404);
        }

        $auditLog = $audit->log(
            (string) $request->input('event'),
            $bundle->id,
            null,
            $request->input('notes'),
            $request->input('metadata'),
            null,
            null,
            $request->input('artifact_s3_key')
        );

        return $this->sendResponse($auditLog, 'Asset sync log recorded', [], 201);
    }
}
