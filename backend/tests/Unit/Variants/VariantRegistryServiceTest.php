<?php

namespace Tests\Unit\Variants;

use App\Services\Variants\VariantRegistryService;
use Tests\TestCase;

class VariantRegistryServiceTest extends TestCase
{
    public function test_variant_id_is_stable_and_deterministic(): void
    {
        $service = new VariantRegistryService();

        $id1 = $service->buildVariantId(
            effectRevisionId: 42,
            workflowId: 9,
            executionEnvironmentId: 3,
            stage: 'staging',
            experimentVariantId: 7
        );

        $id2 = $service->buildVariantId(
            effectRevisionId: 42,
            workflowId: 9,
            executionEnvironmentId: 3,
            stage: 'staging',
            experimentVariantId: 7
        );

        $id3 = $service->buildVariantId(
            effectRevisionId: 42,
            workflowId: 9,
            executionEnvironmentId: 3,
            stage: 'staging',
            experimentVariantId: 8
        );

        $this->assertSame($id1, $id2);
        $this->assertNotSame($id1, $id3);
        $this->assertStringContainsString('er:42', $id1);
        $this->assertStringContainsString('wf:9', $id1);
        $this->assertStringContainsString('env:3', $id1);
    }
}

