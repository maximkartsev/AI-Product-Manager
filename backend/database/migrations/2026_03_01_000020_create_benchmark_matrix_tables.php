<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('benchmark_matrix_runs')) {
            Schema::connection($this->connection)->create('benchmark_matrix_runs', function (Blueprint $table) {
                $table->id();
                $table->string('benchmark_context_id', 120)->unique();
                $table->unsignedBigInteger('effect_revision_id')->index();
                $table->string('stage', 32)->default('staging')->index();
                $table->string('status', 32)->default('queued')->index();
                $table->unsignedInteger('runs_per_variant')->default(1);
                $table->unsignedInteger('variant_count')->default(0);
                $table->json('metrics_json')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('benchmark_matrix_run_items')) {
            Schema::connection($this->connection)->create('benchmark_matrix_run_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('benchmark_matrix_run_id')->index();
                $table->string('variant_id', 255)->index();
                $table->unsignedBigInteger('execution_environment_id')->nullable()->index();
                $table->string('provider', 100)->nullable()->index();
                $table->unsignedBigInteger('experiment_variant_id')->nullable()->index();
                $table->unsignedBigInteger('effect_test_run_id')->nullable()->index();
                $table->unsignedInteger('dispatch_count')->default(0);
                $table->string('status', 32)->default('queued')->index();
                $table->json('metrics_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('benchmark_matrix_run_items');
        Schema::connection($this->connection)->dropIfExists('benchmark_matrix_runs');
    }
};

