<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CategoriesSeeder extends Seeder
{
    /**
     * Seed categories from workflow info.json files (idempotent).
     */
    public function run(): void
    {
        $root = base_path('resources/comfyui/workflows');
        if (!File::isDirectory($root)) {
            return;
        }

        $folders = File::directories($root);
        sort($folders);

        foreach ($folders as $folder) {
            $infoPath = $folder . DIRECTORY_SEPARATOR . 'info.json';
            if (!File::exists($infoPath)) {
                continue;
            }

            $infoRaw = File::get($infoPath);
            $info = json_decode($infoRaw, true);
            if (!is_array($info)) {
                $this->command?->warn("Invalid info.json (skipped): {$infoPath}");
                continue;
            }

            $categoryName = $this->parseCategoryName($info['category'] ?? null);
            if (!$categoryName) {
                $this->command?->warn("Missing category in info.json (skipped): {$infoPath}");
                continue;
            }

            $slug = Str::slug($categoryName);
            if ($slug === '') {
                $this->command?->warn("Invalid category slug (skipped): {$infoPath}");
                continue;
            }

            Category::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $categoryName],
            );
        }
    }

    private function parseCategoryName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
