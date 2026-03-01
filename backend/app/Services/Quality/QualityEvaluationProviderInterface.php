<?php

namespace App\Services\Quality;

interface QualityEvaluationProviderInterface
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function evaluate(array $request): array;
}

