<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('quality_evaluations')) {
            Schema::connection($this->connection)->create('quality_evaluations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('benchmark_matrix_run_id')->nullable()->index();
                $table->unsignedBigInteger('benchmark_matrix_run_item_id')->nullable()->index();
                $table->unsignedBigInteger('effect_test_run_id')->nullable()->index();
                $table->string('benchmark_context_id', 120)->nullable()->index();
                $table->string('rubric_version', 40)->default('v1');
                $table->string('provider', 60)->default('gemini');
                $table->string('model', 120)->nullable();
                $table->string('status', 32)->default('completed')->index();
                $table->decimal('composite_score', 8, 4)->nullable();
                $table->json('vector_json')->nullable();
                $table->json('request_json')->nullable();
                $table->json('result_json')->nullable();
                $table->timestamp('evaluated_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('quality_evaluations');
    }
};

