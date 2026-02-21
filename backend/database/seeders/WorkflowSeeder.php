<?php

namespace Database\Seeders;

use App\Models\Workflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class WorkflowSeeder extends Seeder
{
    /**
     * Create the shared workflow record used by all effects (idempotent).
     *
     * All 22 effects share the same 4-node ComfyUI structure:
     * LoadVideo → LoadImage → KlingOmniProVideoToVideoNode → SaveVideo
     *
     * This creates ONE reusable workflow template with placeholders.
     */
    public function run(): void
    {
        $root = base_path('resources/comfyui/workflows');
        if (!File::isDirectory($root)) {
            return;
        }

        // Find the first workflow JSON to use as template base
        $folders = File::directories($root);
        sort($folders);

        $templateJson = null;
        foreach ($folders as $folder) {
            $jsonPath = $this->findWorkflowJson($folder);
            if ($jsonPath) {
                $raw = File::get($jsonPath);
                $templateJson = json_decode($raw, true);
                if (is_array($templateJson) && !empty($templateJson)) {
                    break;
                }
            }
        }

        if (!$templateJson) {
            $this->command?->warn('No workflow JSON found to create template.');
            return;
        }

        // Create template with placeholders
        // Node 7 (LoadVideo): already has __INPUT_PATH__
        // Node 6 (KlingOmniProVideoToVideoNode): replace hardcoded prompt
        if (isset($templateJson['6']['inputs']['prompt'])) {
            $templateJson['6']['inputs']['prompt'] = '__POSITIVE_PROMPT__';
        }
        // Node 3 (LoadImage): replace hardcoded image filename
        if (isset($templateJson['3']['inputs']['image'])) {
            $templateJson['3']['inputs']['image'] = '__STYLE_IMAGE__';
        }

        // Upload template to S3
        $disk = (string) config('filesystems.default', 's3');
        $templatePath = 'workflows/1/workflow.json';
        $templateContent = json_encode($templateJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        Storage::disk($disk)->put($templatePath, $templateContent, [
            'visibility' => 'private',
            'ContentType' => 'application/json',
        ]);

        // Create the workflow record (withTrashed to avoid unique constraint conflict with soft-deleted rows)
        $workflow = Workflow::withTrashed()->updateOrCreate(
            ['slug' => 'kling-video-to-video'],
            [
                'name' => 'Kling Video to Video',
                'description' => 'Kling Omni Pro video-to-video transformation with style image and prompt',
                'comfyui_workflow_path' => $templatePath,
                'output_node_id' => '1',
                'output_extension' => 'mp4',
                'output_mime_type' => 'video/mp4',
                'is_active' => true,
                'properties' => [
                    [
                        'key' => 'input_video',
                        'name' => 'Input Video',
                        'description' => 'The user\'s video to transform',
                        'type' => 'video',
                        'placeholder' => '__INPUT_PATH__',
                        'default_value' => null,
                        'default_value_hash' => null,
                        'required' => true,
                        'user_configurable' => false,
                        'is_primary_input' => true,
                    ],
                    [
                        'key' => 'positive_prompt',
                        'name' => 'Style Prompt',
                        'description' => 'Describe the transformation style',
                        'type' => 'text',
                        'placeholder' => '__POSITIVE_PROMPT__',
                        'default_value' => '',
                        'default_value_hash' => null,
                        'required' => false,
                        'user_configurable' => true,
                        'is_primary_input' => false,
                    ],
                    [
                        'key' => 'style_image',
                        'name' => 'Style Reference Image',
                        'description' => 'Reference image for the visual style',
                        'type' => 'image',
                        'placeholder' => '__STYLE_IMAGE__',
                        'default_value' => null,
                        'default_value_hash' => null,
                        'required' => false,
                        'user_configurable' => false,
                        'is_primary_input' => false,
                    ],
                ],
            ]
        );
        if ($workflow->trashed()) {
            $workflow->restore();
        }

        $this->command?->info('Workflow "kling-video-to-video" created/updated.');

        // Clean up orphaned workflows (no effects linked) that may exist
        // from an earlier version of the data migration.
        $orphans = Workflow::withTrashed()
            ->whereDoesntHave('effects')
            ->whereDoesntHave('fleets')
            ->where('slug', '!=', 'kling-video-to-video')
            ->get();

        foreach ($orphans as $orphan) {
            $orphan->workers()->detach();
            $orphan->forceDelete();
        }

        if ($orphans->count() > 0) {
            $this->command?->info("Removed {$orphans->count()} orphaned workflow(s).");
        }
    }

    private function findWorkflowJson(string $folder): ?string
    {
        foreach (File::files($folder) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            if (strtolower($file->getFilename()) === 'info.json') {
                continue;
            }
            return $file->getPathname();
        }
        return null;
    }
}
