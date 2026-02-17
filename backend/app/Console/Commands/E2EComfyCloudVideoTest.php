<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Models\Effect;
use App\Models\File as TenantFile;
use App\Models\Tenant;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;

class E2EComfyCloudVideoTest extends Command
{
    protected $signature = 'e2e:comfy-cloud-video
        {--input= : Path to a local video file}
        {--workflow= : Path to a Comfy Cloud workflow JSON file}
        {--output-node-id= : Output node id that emits the final video}
        {--input-node-id= : Optional input node id for direct injection}
        {--input-field= : Optional input field name for direct injection}
        {--api-base= : Base API URL (e.g. http://localhost:80 or http://nginx)}
        {--timeout=3600 : Max seconds to wait for job completion}
        {--poll-interval=5 : Seconds between status checks}
        {--wallet-balance=100 : Initial token balance for the test user}
        {--save-to= : Folder to save output video (default: resources/comfyui/output)}';

    protected $description = 'Run end-to-end Comfy Cloud video test (upload → cloud → MinIO).';

    public function handle(): int
    {
        $inputPath = (string) $this->option('input');
        if ($inputPath === '') {
            $this->error('Missing --input path.');
            return self::FAILURE;
        }
        $inputPath = realpath($inputPath) ?: $inputPath;
        if (!is_file($inputPath)) {
            $this->error("Input file not found: {$inputPath}");
            return self::FAILURE;
        }

        $workflowPath = (string) ($this->option('workflow') ?: base_path('resources/comfyui/workflows/cloud_video_effect.json'));
        if (!is_file($workflowPath)) {
            $this->error("Workflow file not found: {$workflowPath}");
            return self::FAILURE;
        }

        $workflowRaw = file_get_contents($workflowPath);
        $workflow = json_decode($workflowRaw ?: '', true);
        if (!is_array($workflow)) {
            $this->error('Workflow JSON is invalid or empty.');
            return self::FAILURE;
        }
        if (!empty($workflow['_placeholder'])) {
            $this->error('Workflow file is a placeholder. Export a real Comfy Cloud workflow and replace it.');
            return self::FAILURE;
        }

        $outputNodeId = (string) $this->option('output-node-id');
        if ($outputNodeId === '') {
            $this->error('Missing --output-node-id (required to find the output video).');
            return self::FAILURE;
        }

        $inputNodeId = $this->option('input-node-id');
        $inputField = $this->option('input-field');
        if (($inputNodeId && !$inputField) || (!$inputNodeId && $inputField)) {
            $this->error('Provide both --input-node-id and --input-field (or neither).');
            return self::FAILURE;
        }

        $apiBase = rtrim((string) ($this->option('api-base') ?: config('app.url')), '/');
        if ($apiBase === '') {
            $this->error('Missing API base URL. Set APP_URL or pass --api-base.');
            return self::FAILURE;
        }

        if (config('filesystems.default') !== 's3') {
            $this->warn('filesystems.default is not s3. MinIO/S3 URLs may not be used.');
        }

        $mimeType = mime_content_type($inputPath) ?: 'video/mp4';
        $fileSize = filesize($inputPath);
        if ($fileSize === false) {
            $this->error('Unable to determine input file size.');
            return self::FAILURE;
        }
        $originalFilename = basename($inputPath);

        $this->info('Registering test user...');
        $password = Str::random(16);
        $email = 'e2e_' . Str::lower(Str::random(10)) . '@example.test';
        $name = 'E2E User ' . Str::random(6);

        $registerResponse = Http::acceptJson()
            ->timeout(30)
            ->post("{$apiBase}/api/register", [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'c_password' => $password,
            ]);

        if (!$registerResponse->ok()) {
            $this->error('Register request failed: ' . $registerResponse->body());
            return self::FAILURE;
        }

        $registerData = $registerResponse->json();
        if (!data_get($registerData, 'success')) {
            $this->error('Register failed: ' . json_encode($registerData));
            return self::FAILURE;
        }

        $token = (string) data_get($registerData, 'data.token');
        $tenantDomain = (string) data_get($registerData, 'data.tenant.domain');
        $tenantId = (string) data_get($registerData, 'data.tenant.id');

        if ($token === '' || $tenantDomain === '' || $tenantId === '') {
            $this->error('Register response missing token or tenant domain.');
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (!$user) {
            $this->error('Unable to load created user.');
            return self::FAILURE;
        }

        $this->info("Tenant domain: {$tenantDomain}");

        $effect = $this->createEffect();
        $this->seedWallet($tenantId, $user->id, (int) $this->option('wallet-balance'));

        $tenantClient = Http::acceptJson()
            ->withHeaders(['Host' => $tenantDomain])
            ->withToken($token);

        $this->info('Requesting upload URL...');
        $uploadResponse = $tenantClient
            ->timeout(30)
            ->post("{$apiBase}/api/videos/uploads", [
                'effect_id' => $effect->id,
                'mime_type' => $mimeType,
                'size' => $fileSize,
                'original_filename' => $originalFilename,
            ]);

        if (!$uploadResponse->ok() || !data_get($uploadResponse->json(), 'success')) {
            $this->error('Upload init failed: ' . $uploadResponse->body());
            return self::FAILURE;
        }

        $uploadData = $uploadResponse->json();
        $uploadUrl = (string) data_get($uploadData, 'data.upload_url');
        $uploadHeaders = (array) data_get($uploadData, 'data.upload_headers', []);
        $inputFileId = (int) data_get($uploadData, 'data.file.id');

        if ($uploadUrl === '' || $inputFileId <= 0) {
            $this->error('Upload init missing upload_url or file id.');
            return self::FAILURE;
        }

        $this->info('Uploading video to MinIO...');
        $stream = fopen($inputPath, 'rb');
        if ($stream === false) {
            $this->error('Unable to open input file for upload.');
            return self::FAILURE;
        }

        $uploadPut = Http::timeout(300)
            ->withHeaders($uploadHeaders)
            ->withBody($stream, $mimeType)
            ->put($uploadUrl);
        fclose($stream);

        if (!$uploadPut->successful()) {
            $this->error('Upload failed: ' . $uploadPut->body());
            return self::FAILURE;
        }

        $this->info('Creating video record...');
        $videoResponse = $tenantClient
            ->timeout(30)
            ->post("{$apiBase}/api/videos", [
                'effect_id' => $effect->id,
                'original_file_id' => $inputFileId,
                'title' => 'E2E Comfy Cloud Video ' . now()->toDateTimeString(),
            ]);

        if (!$videoResponse->ok() || !data_get($videoResponse->json(), 'success')) {
            $this->error('Video create failed: ' . $videoResponse->body());
            return self::FAILURE;
        }

        $videoId = (int) data_get($videoResponse->json(), 'data.id');
        if ($videoId <= 0) {
            $this->error('Video create missing video id.');
            return self::FAILURE;
        }

        $this->info('Submitting AI job...');
        $inputPayload = [
            'workflow' => $workflow,
            'input_path_placeholder' => '__INPUT_PATH__',
            'output_node_id' => $outputNodeId,
            'output_extension' => 'mp4',
            'output_mime_type' => 'video/mp4',
            'input_mime_type' => $mimeType,
            'input_name' => $originalFilename,
        ];

        if ($inputNodeId && $inputField) {
            $inputPayload['input_node_id'] = $inputNodeId;
            $inputPayload['input_field'] = $inputField;
        }

        $jobResponse = $tenantClient
            ->timeout(30)
            ->post("{$apiBase}/api/ai-jobs", [
                'effect_id' => $effect->id,
                'video_id' => $videoId,
                'provider' => 'cloud',
                'idempotency_key' => 'e2e_' . Str::uuid()->toString(),
                'input_payload' => $inputPayload,
            ]);

        if (!$jobResponse->ok() || !data_get($jobResponse->json(), 'success')) {
            $this->error('AI job submit failed: ' . $jobResponse->body());
            return self::FAILURE;
        }

        $jobId = (int) data_get($jobResponse->json(), 'data.id');
        if ($jobId <= 0) {
            $this->error('AI job submit missing job id.');
            return self::FAILURE;
        }

        $this->info("Job submitted: {$jobId}");
        $downloadUrl = $this->waitForCompletion($tenantId, $jobId);

        if (!$downloadUrl) {
            return self::FAILURE;
        }

        $this->info('Job completed.');
        $this->line("Download URL: {$downloadUrl}");
        $savedPath = $this->saveOutput($downloadUrl, $jobId);
        if (!$savedPath) {
            return self::FAILURE;
        }
        $this->info("Saved output: {$savedPath}");

        return self::SUCCESS;
    }

    private function createEffect(): Effect
    {
        $slug = 'e2e-video-' . Str::lower(Str::random(8));
        $attrs = [
            'name' => 'E2E Comfy Cloud Video',
            'slug' => $slug,
            'description' => 'E2E Comfy Cloud video effect',
        ];

        if (Schema::connection('central')->hasColumn('effects', 'type')) {
            $attrs['type'] = 'video';
        }
        if (Schema::connection('central')->hasColumn('effects', 'credits_cost')) {
            $attrs['credits_cost'] = 5;
        }
        if (Schema::connection('central')->hasColumn('effects', 'processing_time_estimate')) {
            $attrs['processing_time_estimate'] = 30;
        }
        if (Schema::connection('central')->hasColumn('effects', 'popularity_score')) {
            $attrs['popularity_score'] = 0;
        }
        if (Schema::connection('central')->hasColumn('effects', 'sort_order')) {
            $attrs['sort_order'] = 0;
        }
        if (Schema::connection('central')->hasColumn('effects', 'is_active')) {
            $attrs['is_active'] = true;
        }
        if (Schema::connection('central')->hasColumn('effects', 'is_premium')) {
            $attrs['is_premium'] = false;
        }
        if (Schema::connection('central')->hasColumn('effects', 'is_new')) {
            $attrs['is_new'] = false;
        }

        return Effect::query()->create($attrs);
    }

    private function seedWallet(string $tenantId, int $userId, int $balance): void
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();
        if (!$tenant) {
            $this->warn('Tenant not found for wallet seeding.');
            return;
        }

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            $wallet = TokenWallet::query()->firstOrCreate(
                ['tenant_id' => $tenantId],
                ['user_id' => $userId, 'balance' => 0]
            );
            if ((int) $wallet->user_id !== (int) $userId) {
                $this->warn('Wallet user mismatch; not updating balance.');
                return;
            }
            $wallet->balance = max((int) $wallet->balance, $balance);
            $wallet->save();
        } finally {
            $tenancy->end();
        }
    }

    private function waitForCompletion(string $tenantId, int $jobId): ?string
    {
        $timeout = (int) $this->option('timeout');
        $pollInterval = (int) $this->option('poll-interval');
        $deadline = time() + max(1, $timeout);

        $tenant = Tenant::query()->whereKey($tenantId)->first();
        if (!$tenant) {
            $this->error('Tenant not found for polling.');
            return null;
        }

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            while (time() < $deadline) {
                $job = AiJob::query()->find($jobId);
                if (!$job) {
                    $this->error('AI job not found while polling.');
                    return null;
                }

                if ($job->status === 'completed') {
                    if (!$job->output_file_id) {
                        $this->error('Job completed but output file id is missing.');
                        return null;
                    }

                    $file = TenantFile::query()->find($job->output_file_id);
                    if (!$file) {
                        $this->error('Output file not found.');
                        return null;
                    }

                    $disk = $file->disk ?: config('filesystems.default');
                    $diskInstance = Storage::disk($disk);
                    if (method_exists($diskInstance, 'temporaryUrl')) {
                        return (string) $diskInstance->temporaryUrl(
                            $file->path,
                            now()->addMinutes(15)
                        );
                    }
                    if ($file->url) {
                        return (string) $file->url;
                    }

                    $this->error('Output file URL not available.');
                    return null;
                }

                if ($job->status === 'failed') {
                    $this->error('Job failed: ' . ($job->error_message ?: 'unknown error'));
                    return null;
                }

                sleep(max(1, $pollInterval));
            }
        } finally {
            $tenancy->end();
        }

        $this->error('Timed out waiting for job completion.');
        return null;
    }

    private function saveOutput(string $downloadUrl, int $jobId): ?string
    {
        $saveTo = (string) $this->option('save-to');
        if ($saveTo === '') {
            $saveTo = base_path('resources/comfyui/output');
        }

        if (!is_dir($saveTo) && !mkdir($saveTo, 0755, true) && !is_dir($saveTo)) {
            $this->error("Failed to create output folder: {$saveTo}");
            return null;
        }

        $path = (string) parse_url($downloadUrl, PHP_URL_PATH);
        $filename = $path !== '' ? basename($path) : "output-{$jobId}.mp4";
        $destination = rtrim($saveTo, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $response = Http::timeout(300)
            ->withOptions(['sink' => $destination])
            ->get($downloadUrl);

        if (!$response->successful()) {
            $this->error('Failed to download output: ' . $response->body());
            return null;
        }

        return $destination;
    }
}
