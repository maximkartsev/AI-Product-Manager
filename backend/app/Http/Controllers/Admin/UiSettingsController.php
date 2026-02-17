<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UiSettingsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->sendResponse(
            $user->admin_ui_settings ?? [],
            'UI settings retrieved successfully'
        );
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $incoming = $request->input('settings', []);

        if (!is_array($incoming)) {
            return $this->sendError('Settings must be an object.', [], 422);
        }

        $existing = $user->admin_ui_settings ?? [];
        $merged = array_merge($existing, $incoming);

        $user->admin_ui_settings = $merged;
        $user->save();

        return $this->sendResponse($merged, 'UI settings updated successfully');
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->admin_ui_settings = null;
        $user->save();

        return $this->sendResponse([], 'UI settings reset successfully');
    }
}
