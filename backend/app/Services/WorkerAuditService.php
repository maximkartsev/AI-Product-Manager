<?php

namespace App\Services;

use App\Models\WorkerAuditLog;

class WorkerAuditService
{
    public function log(
        string $event,
        ?int $workerId = null,
        ?string $workerIdentifier = null,
        ?int $dispatchId = null,
        ?string $ip = null,
        ?array $metadata = null
    ): WorkerAuditLog {
        return WorkerAuditLog::query()->create([
            'worker_id' => $workerId,
            'worker_identifier' => $workerIdentifier,
            'event' => $event,
            'dispatch_id' => $dispatchId,
            'ip_address' => $ip,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
