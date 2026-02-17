<?php

namespace Database\Seeders;

use App\Models\ComfyUiWorker;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class WorkerSeeder extends Seeder
{
    /**
     * Seed development worker records (idempotent).
     */
    public function run(): void
    {
        $worker = ComfyUiWorker::query()->updateOrCreate(
            ['worker_id' => 'seed-worker-local'],
            [
                'display_name' => 'Local Dev Worker',
                'environment' => 'self_hosted',
                'capabilities' => ['gpu' => 'seed'],
                'max_concurrency' => 1,
                'is_approved' => true,
                'is_draining' => false,
            ]
        );

        // Generate and store a deterministic seed token
        $seedToken = 'seed-worker-token-for-development';
        $worker->token_hash = hash('sha256', $seedToken);
        $worker->save();

        // Assign the workflow to the worker
        $workflow = Workflow::query()->where('slug', 'kling-video-to-video')->first();
        if ($workflow) {
            $worker->workflows()->syncWithoutDetaching([$workflow->id]);
        }

        $this->command?->info("Seed worker token: {$seedToken}");
    }
}
