<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('ai_job_dispatches') && $schema->hasColumn('ai_job_dispatches', 'provider')) {
            $schema->table('ai_job_dispatches', function (Blueprint $table) {
                try {
                    $table->dropIndex(['provider']);
                } catch (\Throwable) {
                    // best effort for environments with divergent index names/state
                }
            });

            $schema->table('ai_job_dispatches', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }

        if ($schema->hasTable('effect_variant_bindings') && $schema->hasColumn('effect_variant_bindings', 'provider')) {
            $schema->table('effect_variant_bindings', function (Blueprint $table) {
                try {
                    $table->dropIndex(['provider']);
                } catch (\Throwable) {
                    // best effort for environments with divergent index names/state
                }
            });

            $schema->table('effect_variant_bindings', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }

        if ($schema->hasTable('benchmark_matrix_run_items') && $schema->hasColumn('benchmark_matrix_run_items', 'provider')) {
            $schema->table('benchmark_matrix_run_items', function (Blueprint $table) {
                try {
                    $table->dropIndex(['provider']);
                } catch (\Throwable) {
                    // best effort for environments with divergent index names/state
                }
            });

            $schema->table('benchmark_matrix_run_items', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('ai_job_dispatches') && !$schema->hasColumn('ai_job_dispatches', 'provider')) {
            $schema->table('ai_job_dispatches', function (Blueprint $table) {
                $table->string('provider', 50)->default('self_hosted')->index()->after('tenant_job_id');
            });
        }

        if ($schema->hasTable('effect_variant_bindings') && !$schema->hasColumn('effect_variant_bindings', 'provider')) {
            $schema->table('effect_variant_bindings', function (Blueprint $table) {
                $table->string('provider', 100)->nullable()->index()->after('execution_environment_id');
            });
        }

        if ($schema->hasTable('benchmark_matrix_run_items') && !$schema->hasColumn('benchmark_matrix_run_items', 'provider')) {
            $schema->table('benchmark_matrix_run_items', function (Blueprint $table) {
                $table->string('provider', 100)->nullable()->index()->after('execution_environment_id');
            });
        }
    }
};
