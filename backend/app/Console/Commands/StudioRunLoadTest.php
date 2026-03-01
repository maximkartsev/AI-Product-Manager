<?php

namespace App\Console\Commands;

use App\Models\LoadTestRun;
use App\Services\LoadTesting\LoadTestRunnerService;
use Illuminate\Console\Command;

class StudioRunLoadTest extends Command
{
    protected $signature = 'studio:run-load-test {--run-id=} {--dry-run}';

    protected $description = 'Execute a load test scenario run (inline runner)';

    public function handle(): int
    {
        $runId = (int) $this->option('run-id');
        if ($runId <= 0) {
            $this->error('Missing required --run-id option.');

            return self::FAILURE;
        }

        $run = LoadTestRun::query()->find($runId);
        if (!$run) {
            $this->error("Load test run {$runId} was not found.");

            return self::FAILURE;
        }

        try {
            $result = app(LoadTestRunnerService::class)->run($run, (bool) $this->option('dry-run'));
        } catch (\Throwable $e) {
            $run->status = 'failed';
            $run->completed_at = now();
            $run->metrics_json = array_merge($run->metrics_json ?? [], [
                'runner_error' => $e->getMessage(),
            ]);
            $run->save();

            $this->error('Load test execution failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Load test execution completed.');
        $this->line(json_encode($result, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
