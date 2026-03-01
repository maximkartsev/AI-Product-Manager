<?php

namespace App\Services\LoadTesting;

use App\Services\LoadTest\FaultInjectionService;
use App\Models\LoadTestRun;
use App\Models\LoadTestStage;

class LoadTestFaultInjector
{
    public function __construct(
        private readonly FaultInjectionService $faultInjectionService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function injectForStage(LoadTestRun $run, LoadTestStage $stage): array
    {
        return $this->faultInjectionService->injectForStage($run, $stage);
    }
}

