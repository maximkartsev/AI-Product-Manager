<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            if (!Schema::hasColumn('effects', 'preview_video_url')) {
                $table->string('preview_video_url', 2048)->nullable()->after('thumbnail_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        if (!Schema::hasColumn('effects', 'preview_video_url')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            $table->dropColumn('preview_video_url');
        });
    }
};

