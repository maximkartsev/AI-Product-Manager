<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gallery_videos')) {
            return;
        }

        Schema::table('gallery_videos', function (Blueprint $table) {
            try {
                $table->dropForeign(['video_id']);
            } catch (\Throwable $e) {
                // ignore if constraint is missing or named differently
            }
        });
    }

    public function down(): void
    {
        // No-op: central gallery videos should not reference tenant tables via FK.
    }
};
