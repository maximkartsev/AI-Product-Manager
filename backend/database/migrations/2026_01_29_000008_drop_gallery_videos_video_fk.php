<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gallery_videos')) {
            return;
        }

        // IMPORTANT:
        // Putting a try/catch *inside* the Schema::table closure doesn't work because Laravel
        // executes the SQL AFTER the closure returns. Catch at this level instead.

        // Best-effort attempt to drop any FK on `video_id` (older schemas). Fresh installs
        // won't have this FK at all, so this must be safe to no-op.
        $constraintName = null;

        try {
            // Only MySQL/MariaDB expose information_schema.KEY_COLUMN_USAGE in this way.
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $row = DB::selectOne(
                    "SELECT CONSTRAINT_NAME AS constraint_name
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                       AND COLUMN_NAME = ?
                       AND REFERENCED_TABLE_NAME IS NOT NULL
                     LIMIT 1",
                    ['gallery_videos', 'video_id'],
                );

                $constraintName = is_object($row) ? ($row->constraint_name ?? null) : null;
            }
        } catch (\Throwable $e) {
            // ignore introspection errors; we will fall back to Laravel's default naming
        }

        if (is_string($constraintName) && $constraintName !== '') {
            try {
                DB::statement("ALTER TABLE `gallery_videos` DROP FOREIGN KEY `{$constraintName}`");
            } catch (\Throwable $e) {
                // ignore if already gone
            }

            return;
        }

        // Fallback: try the default Laravel foreign key name (`gallery_videos_video_id_foreign`).
        try {
            Schema::table('gallery_videos', function (Blueprint $table) {
                $table->dropForeign(['video_id']);
            });
        } catch (\Throwable $e) {
            // ignore if constraint is missing or named differently
        }
    }

    public function down(): void
    {
        // No-op: central gallery videos should not reference tenant tables via FK.
    }
};
