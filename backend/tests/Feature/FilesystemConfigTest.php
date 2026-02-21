<?php

namespace Tests\Feature;

use Tests\TestCase;

class FilesystemConfigTest extends TestCase
{
    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_comfyui_models_uses_dedicated_env_vars(): void
    {
        $this->setEnv('AWS_ENDPOINT', 'http://minio.local');
        $this->setEnv('COMFYUI_MODELS_ENDPOINT', 'https://s3.amazonaws.com');
        $this->setEnv('AWS_ACCESS_KEY_ID', 'local-media-key');
        $this->setEnv('COMFYUI_MODELS_ACCESS_KEY_ID', 'models-key');
        $this->setEnv('AWS_DEFAULT_REGION', 'us-east-1');
        $this->setEnv('COMFYUI_MODELS_REGION', 'us-west-2');

        $config = require config_path('filesystems.php');

        $this->assertSame('http://minio.local', $config['disks']['s3']['endpoint']);
        $this->assertSame('https://s3.amazonaws.com', $config['disks']['comfyui_models']['endpoint']);
        $this->assertSame('local-media-key', $config['disks']['s3']['key']);
        $this->assertSame('models-key', $config['disks']['comfyui_models']['key']);
        $this->assertSame('us-east-1', $config['disks']['s3']['region']);
        $this->assertSame('us-west-2', $config['disks']['comfyui_models']['region']);
    }

    public function test_comfyui_logs_uses_dedicated_env_vars(): void
    {
        $this->setEnv('AWS_ENDPOINT', 'http://minio.local');
        $this->setEnv('COMFYUI_LOGS_ENDPOINT', 'https://s3.amazonaws.com');
        $this->setEnv('AWS_ACCESS_KEY_ID', 'local-media-key');
        $this->setEnv('COMFYUI_LOGS_ACCESS_KEY_ID', 'logs-key');

        $config = require config_path('filesystems.php');

        $this->assertSame('http://minio.local', $config['disks']['s3']['endpoint']);
        $this->assertSame('https://s3.amazonaws.com', $config['disks']['comfyui_logs']['endpoint']);
        $this->assertSame('local-media-key', $config['disks']['s3']['key']);
        $this->assertSame('logs-key', $config['disks']['comfyui_logs']['key']);
    }
}
