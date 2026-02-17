<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TranslationsController extends BaseController
{
    public function show(string $lang)
    {
        $path = resource_path("lang/{$lang}.json");

        if (!file_exists($path)) {
            return $this->sendError('Language file not found', [], 404);
        }

        $translations = json_decode(file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->sendError('Invalid language file', [], 500);
        }

        return $this->sendResponse($translations, null);
    }
}
