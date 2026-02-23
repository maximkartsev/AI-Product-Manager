<?php

namespace App\Services;

use Aws\Ssm\SsmClient;

class ComfyUiFleetSsmService
{
    public function putActiveBundle(string $stage, string $fleetSlug, string $bundlePrefix): void
    {
        $region = (string) config('services.comfyui.aws_region', 'us-east-1');
        $client = new SsmClient([
            'version' => 'latest',
            'region' => $region,
        ]);

        $client->putParameter([
            'Name' => "/bp/{$stage}/fleets/{$fleetSlug}/active_bundle",
            'Value' => $bundlePrefix,
            'Type' => 'String',
            'Overwrite' => true,
        ]);
    }

    public function putDesiredFleetConfig(string $stage, string $fleetSlug, array $payload): void
    {
        $region = (string) config('services.comfyui.aws_region', 'us-east-1');
        $client = new SsmClient([
            'version' => 'latest',
            'region' => $region,
        ]);

        $client->putParameter([
            'Name' => "/bp/{$stage}/fleets/{$fleetSlug}/desired_config",
            'Value' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'Type' => 'String',
            'Overwrite' => true,
        ]);
    }
}
