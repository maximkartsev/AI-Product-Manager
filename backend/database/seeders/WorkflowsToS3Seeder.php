<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class WorkflowsToS3Seeder extends Seeder
{
    public function run(): void
    {
        $disk = (string) config('services.comfyui.workflow_disk', 's3');
        $root = base_path('resources/comfyui/workflows');

        if (!File::isDirectory($root)) {
            return;
        }

        $files = File::allFiles($root);
        $basePath = str_replace('\\', '/', base_path());

        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $fullPath = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($fullPath, $basePath . '/')) {
                continue;
            }

            $relative = substr($fullPath, strlen($basePath) + 1);
            $contents = File::get($file->getPathname());

            Storage::disk($disk)->put($relative, $contents, [
                'visibility' => 'private',
                'ContentType' => 'application/json',
            ]);
        }
    }
}
