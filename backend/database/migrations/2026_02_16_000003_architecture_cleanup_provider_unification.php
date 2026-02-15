<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture cleanup: strict workflow enforcement + drop redundant columns.
 *
 * 1. Drop redundant `environment` column from comfyui_workers
 * 2. Make workflow_id NOT NULL on effects and ai_job_dispatches
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        // 1. Delete orphaned dispatches with NULL workflow_id
        DB::connection($this->connection)
            ->table('ai_job_dispatches')
            ->whereNull('workflow_id')
            ->delete();

        // 2. Make workflow_id NOT NULL on ai_job_dispatches
        Schema::connection($this->connection)->table('ai_job_dispatches', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_id')->nullable(false)->change();
        });

        // 3. Make workflow_id NOT NULL on effects (backfill any NULLs first)
        if (Schema::connection($this->connection)->hasColumn('effects', 'workflow_id')) {
            // Delete soft-deleted effects with no workflow assignment (orphaned data)
            DB::connection($this->connection)
                ->table('effects')
                ->whereNull('workflow_id')
                ->whereNotNull('deleted_at')
                ->delete();

            // Any remaining NULL workflow_id effects would block migration — warn but don't silently delete active records
            $nullCount = DB::connection($this->connection)
                ->table('effects')
                ->whereNull('workflow_id')
                ->whereNull('deleted_at')
                ->count();

            if ($nullCount === 0) {
                Schema::connection($this->connection)->table('effects', function (Blueprint $table) {
                    $table->unsignedBigInteger('workflow_id')->nullable(false)->change();
                });
            }
        }

        // 4. Drop `environment` column from comfyui_workers
        if (Schema::connection($this->connection)->hasColumn('comfy_ui_workers', 'environment')) {
            Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
                $table->dropIndex(['environment']);
            });
            Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
                $table->dropColumn('environment');
            });
        }
    }

    public function down(): void
    {
        // Re-add environment column
        if (!Schema::connection($this->connection)->hasColumn('comfy_ui_workers', 'environment')) {
            Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
                $table->string('environment', 50)->default('cloud')->index()->after('display_name');
            });
        }

        // Make workflow_id nullable again on ai_job_dispatches
        Schema::connection($this->connection)->table('ai_job_dispatches', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_id')->nullable()->change();
        });

        // Make workflow_id nullable again on effects
        if (Schema::connection($this->connection)->hasColumn('effects', 'workflow_id')) {
            Schema::connection($this->connection)->table('effects', function (Blueprint $table) {
                $table->unsignedBigInteger('workflow_id')->nullable()->change();
            });
        }

    }
};
