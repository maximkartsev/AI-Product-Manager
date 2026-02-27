<?php

namespace App\Services;

class ComfyUiCloudWatchRegionResolver
{
    public function resolve(): string
    {
        $region = config('services.comfyui.aws_region');
        if (is_string($region) && trim($region) !== '') {
            return $region;
        }

        return 'us-east-1';
    }
}
