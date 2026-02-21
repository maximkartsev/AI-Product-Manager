<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    private function indexExists(string $table, string $index): bool
    {
        try {
            $result = DB::connection($this->connection)->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function up(): void
    {
        // ------------------------------------------------------------------
        // Assets: global, content-addressed
        // ------------------------------------------------------------------
        if (Schema::connection($this->connection)->hasTable('comfyui_asset_files')) {
            Schema::connection($this->connection)->table('comfyui_asset_files', function (Blueprint $table) {
                if (Schema::connection($this->connection)->hasColumn('comfyui_asset_files', 'workflow_id')) {
                    try {
                        $table->dropForeign(['workflow_id']);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    try {
                        $table->dropIndex(['workflow_id', 'kind']);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    try {
                        $table->dropIndex(['workflow_id']);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    $table->dropColumn('workflow_id');
                }

                if (!Schema::connection($this->connection)->hasColumn('comfyui_asset_files', 'notes')) {
                    $table->text('notes')->nullable()->after('sha256');
                }
            });

            // Avoid doctrine/dbal dependency; raw SQL for NOT NULL.
            try {
                DB::connection($this->connection)->statement(
                    "ALTER TABLE `comfyui_asset_files` MODIFY `sha256` VARCHAR(128) NOT NULL"
                );
            } catch (\Throwable $e) {
                // ignore (validation will enforce sha256 in app)
            }

            if (!$this->indexExists('comfyui_asset_files', 'comfyui_asset_files_kind_sha256_unique')) {
                try {
                    Schema::connection($this->connection)->table('comfyui_asset_files', function (Blueprint $table) {
                        $table->unique(['kind', 'sha256'], 'comfyui_asset_files_kind_sha256_unique');
                    });
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // ------------------------------------------------------------------
        // Bundles: global (no workflow linkage)
        // ------------------------------------------------------------------
        if (Schema::connection($this->connection)->hasTable('comfyui_asset_bundles')) {
            Schema::connection($this->connection)->table('comfyui_asset_bundles', function (Blueprint $table) {
                if (Schema::connection($this->connection)->hasColumn('comfyui_asset_bundles', 'workflow_id')) {
                    try {
                        $table->dropForeign(['workflow_id']);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    try {
                        $table->dropIndex(['workflow_id']);
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    $table->dropColumn('workflow_id');
                }

                if (!Schema::connection($this->connection)->hasColumn('comfyui_asset_bundles', 'name')) {
                    $table->string('name', 255)->nullable()->after('bundle_id');
                }

                if (Schema::connection($this->connection)->hasColumn('comfyui_asset_bundles', 'active_staging_at')) {
                    $table->dropColumn('active_staging_at');
                }
                if (Schema::connection($this->connection)->hasColumn('comfyui_asset_bundles', 'active_production_at')) {
                    $table->dropColumn('active_production_at');
                }
            });
        }

        // ------------------------------------------------------------------
        // Bundle contents: add action column + prevent target path collisions
        // ------------------------------------------------------------------
        if (Schema::connection($this->connection)->hasTable('comfyui_asset_bundle_files')) {
            Schema::connection($this->connection)->table('comfyui_asset_bundle_files', function (Blueprint $table) {
                if (!Schema::connection($this->connection)->hasColumn('comfyui_asset_bundle_files', 'action')) {
                    $table->string('action', 32)->default('copy')->after('target_path');
                }
            });

            if (!$this->indexExists('comfyui_asset_bundle_files', 'comfyui_asset_bundle_files_bundle_id_target_path_unique')) {
                try {
                    Schema::connection($this->connection)->table('comfyui_asset_bundle_files', function (Blueprint $table) {
                        $table->unique(['bundle_id', 'target_path'], 'comfyui_asset_bundle_files_bundle_id_target_path_unique');
                    });
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // ------------------------------------------------------------------
        // Legacy active-bundle mapping (replaced by fleets)
        // ------------------------------------------------------------------
        Schema::connection($this->connection)->dropIfExists('comfyui_workflow_active_bundles');
    }

    public function down(): void
    {
        // No-op: this migration is designed for forward-only upgrades.
    }
};
