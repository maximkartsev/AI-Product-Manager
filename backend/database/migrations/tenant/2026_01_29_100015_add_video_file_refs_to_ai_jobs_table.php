<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_jobs', 'video_id')) {
                $table->unsignedBigInteger('video_id')->nullable()->after('effect_id');
            }
            if (!Schema::hasColumn('ai_jobs', 'input_file_id')) {
                $table->unsignedBigInteger('input_file_id')->nullable()->after('video_id');
            }
            if (!Schema::hasColumn('ai_jobs', 'output_file_id')) {
                $table->unsignedBigInteger('output_file_id')->nullable()->after('input_file_id');
            }
        });

        Schema::table('ai_jobs', function (Blueprint $table) {
            try {
                $table->index('video_id');
            } catch (\Throwable $e) {
                // ignore if already exists
            }
            try {
                $table->index('input_file_id');
            } catch (\Throwable $e) {
                // ignore if already exists
            }
            try {
                $table->index('output_file_id');
            } catch (\Throwable $e) {
                // ignore if already exists
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            try {
                $table->dropIndex(['output_file_id']);
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                $table->dropIndex(['input_file_id']);
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                $table->dropIndex(['video_id']);
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('ai_jobs', 'output_file_id')) {
                $table->dropColumn('output_file_id');
            }
            if (Schema::hasColumn('ai_jobs', 'input_file_id')) {
                $table->dropColumn('input_file_id');
            }
            if (Schema::hasColumn('ai_jobs', 'video_id')) {
                $table->dropColumn('video_id');
            }
        });
    }
};
