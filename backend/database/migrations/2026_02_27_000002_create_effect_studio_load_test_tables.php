<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('test_input_sets')) {
            Schema::connection($this->connection)->create('test_input_sets', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->json('input_json');
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('effect_test_runs')) {
            Schema::connection($this->connection)->create('effect_test_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('effect_id')->nullable()->index();
                $table->unsignedBigInteger('effect_revision_id')->nullable()->index();
                $table->unsignedBigInteger('workflow_revision_id')->nullable()->index();
                $table->unsignedBigInteger('execution_environment_id')->nullable()->index();
                $table->unsignedBigInteger('test_input_set_id')->nullable()->index();
                $table->string('run_mode', 32)->default('interactive')->index(); // interactive|blackbox
                $table->unsignedInteger('target_count')->default(1);
                $table->json('overrides_json')->nullable();
                $table->string('status', 32)->default('queued')->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->decimal('p50_latency_ms', 14, 3)->nullable();
                $table->decimal('p95_latency_ms', 14, 3)->nullable();
                $table->decimal('p99_latency_ms', 14, 3)->nullable();
                $table->decimal('error_rate_percent', 8, 4)->nullable();
                $table->decimal('compute_cost_usd', 18, 8)->nullable();
                $table->decimal('effective_cost_usd', 18, 8)->nullable();
                $table->decimal('partner_cost_usd', 18, 8)->nullable();
                $table->decimal('margin_usd', 18, 8)->nullable();
                $table->json('metrics_json')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('load_test_scenarios')) {
            Schema::connection($this->connection)->create('load_test_scenarios', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('load_test_stages')) {
            Schema::connection($this->connection)->create('load_test_stages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('load_test_scenario_id')->index();
                $table->unsignedInteger('stage_order')->default(0)->index();
                $table->string('stage_type', 32)->index(); // spike|steady|sine|drop|ramp
                $table->unsignedInteger('duration_seconds');
                $table->decimal('target_rpm', 14, 3)->nullable();
                $table->decimal('target_rps', 14, 3)->nullable();
                $table->boolean('fault_enabled')->default(false);
                $table->string('fault_kind', 64)->nullable(); // instance_termination
                $table->decimal('fault_interruption_rate', 8, 4)->nullable();
                $table->string('fault_target_scope', 32)->nullable(); // spot_only|on_demand_only|mixed
                $table->string('fault_method', 32)->nullable(); // fis|asg_terminate
                $table->unsignedInteger('fault_notice_seconds')->nullable();
                $table->decimal('economics_spot_discount_override', 8, 4)->nullable();
                $table->json('config_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('experiment_variants')) {
            Schema::connection($this->connection)->create('experiment_variants', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('target_environment_kind', 32)->default('test_asg')->index();
                $table->json('fleet_config_intent_json')->nullable();
                $table->json('constraints_json')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('fleet_config_snapshots')) {
            Schema::connection($this->connection)->create('fleet_config_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('execution_environment_id')->nullable()->index();
                $table->unsignedBigInteger('experiment_variant_id')->nullable()->index();
                $table->string('snapshot_scope', 32)->default('run_start')->index();
                $table->json('config_json')->nullable();
                $table->json('composition_json')->nullable();
                $table->timestamp('captured_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('load_test_runs')) {
            Schema::connection($this->connection)->create('load_test_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('load_test_scenario_id')->nullable()->index();
                $table->unsignedBigInteger('execution_environment_id')->nullable()->index();
                $table->unsignedBigInteger('effect_revision_id')->nullable()->index();
                $table->unsignedBigInteger('experiment_variant_id')->nullable()->index();
                $table->unsignedBigInteger('fleet_config_snapshot_start_id')->nullable()->index();
                $table->unsignedBigInteger('fleet_config_snapshot_end_id')->nullable()->index();
                $table->string('status', 32)->default('queued')->index();
                $table->decimal('achieved_rpm', 14, 3)->nullable();
                $table->decimal('achieved_rps', 14, 3)->nullable();
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failure_count')->default(0);
                $table->decimal('p95_latency_ms', 14, 3)->nullable();
                $table->decimal('queue_wait_p95_seconds', 14, 3)->nullable();
                $table->decimal('processing_p95_seconds', 14, 3)->nullable();
                $table->decimal('compute_cost_usd', 18, 8)->nullable();
                $table->decimal('effective_cost_usd', 18, 8)->nullable();
                $table->decimal('partner_cost_usd', 18, 8)->nullable();
                $table->decimal('margin_usd', 18, 8)->nullable();
                $table->json('metrics_json')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('run_artifacts')) {
            Schema::connection($this->connection)->create('run_artifacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('effect_test_run_id')->nullable()->index();
                $table->unsignedBigInteger('load_test_run_id')->nullable()->index();
                $table->string('artifact_type', 64)->index();
                $table->string('storage_disk', 64)->nullable();
                $table->string('storage_path', 2048)->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('production_fleet_snapshots')) {
            Schema::connection($this->connection)->create('production_fleet_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('execution_environment_id')->nullable()->index();
                $table->string('fleet_slug', 255)->nullable()->index();
                $table->string('stage', 32)->default('production')->index();
                $table->timestamp('captured_at')->index();
                $table->json('config_json')->nullable();
                $table->json('composition_json')->nullable();
                $table->json('metrics_json')->nullable();
                $table->unsignedInteger('queue_depth')->nullable();
                $table->decimal('queue_units', 14, 4)->nullable();
                $table->decimal('p95_queue_wait_seconds', 14, 4)->nullable();
                $table->decimal('p95_processing_seconds', 14, 4)->nullable();
                $table->unsignedInteger('interruptions_count')->nullable();
                $table->unsignedInteger('rebalance_recommendations_count')->nullable();
                $table->decimal('spot_discount_estimate', 8, 4)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('production_fleet_snapshots');
        Schema::connection($this->connection)->dropIfExists('run_artifacts');
        Schema::connection($this->connection)->dropIfExists('load_test_runs');
        Schema::connection($this->connection)->dropIfExists('fleet_config_snapshots');
        Schema::connection($this->connection)->dropIfExists('experiment_variants');
        Schema::connection($this->connection)->dropIfExists('load_test_stages');
        Schema::connection($this->connection)->dropIfExists('load_test_scenarios');
        Schema::connection($this->connection)->dropIfExists('effect_test_runs');
        Schema::connection($this->connection)->dropIfExists('test_input_sets');
    }
};
