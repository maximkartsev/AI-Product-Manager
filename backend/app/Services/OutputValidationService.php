<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class OutputValidationService
{
    /**
     * Validate that an output file exists and has valid content.
     *
     * @return array{valid: bool, size?: int, mime_type?: string, error?: string}
     */
    public function validate(string $disk, string $path): array
    {
        try {
            if (!Storage::disk($disk)->exists($path)) {
                return ['valid' => false, 'error' => 'Output file does not exist.'];
            }

            $size = Storage::disk($disk)->size($path);
            if ($size <= 0) {
                return ['valid' => false, 'error' => 'Output file is empty.'];
            }

            $mimeType = Storage::disk($disk)->mimeType($path);

            return [
                'valid' => true,
                'size' => $size,
                'mime_type' => $mimeType ?: null,
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => 'Failed to validate output: ' . $e->getMessage()];
        }
    }
}
