<?php

namespace Tests\Unit;

use App\Services\ComfyUiCloudWatchRegionResolver;
use Tests\TestCase;

class ComfyUiCloudWatchRegionResolverTest extends TestCase
{
    public function test_resolves_region_from_comfyui_service_config(): void
    {
        config()->set('services.comfyui.aws_region', 'eu-west-1');

        $resolver = new ComfyUiCloudWatchRegionResolver();

        $this->assertSame('eu-west-1', $resolver->resolve());
    }

    public function test_uses_us_east_1_when_comfyui_region_missing(): void
    {
        config()->set('services.comfyui.aws_region', null);

        $resolver = new ComfyUiCloudWatchRegionResolver();

        $this->assertSame('us-east-1', $resolver->resolve());
    }
}
