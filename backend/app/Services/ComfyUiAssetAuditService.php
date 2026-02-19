<?php

namespace App\Services;

use App\Models\ComfyUiAssetAuditLog;
use Illuminate\Support\Carbon;

class ComfyUiAssetAuditService
{
    public function log(
        string $event,
        ?int $bundleId = null,
        ?int $assetFileId = null,
        ?string $notes = null,
        ?array $metadata = null,
        ?int $actorUserId = null,
        ?string $actorEmail = null,
        ?string $artifactS3Key = null
    ): ComfyUiAssetAuditLog {
        return ComfyUiAssetAuditLog::query()->create([
            'bundle_id' => $bundleId,
            'asset_file_id' => $assetFileId,
            'event' => $event,
            'notes' => $notes,
            'metadata' => $metadata,
            'artifact_s3_key' => $artifactS3Key,
            'actor_user_id' => $actorUserId,
            'actor_email' => $actorEmail,
            'created_at' => Carbon::now(),
        ]);
    }
}
