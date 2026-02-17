<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('effect_id')->index();
            $table->string('status', 50)->default('queued')->index();
            $table->string('idempotency_key', 255);
            $table->unsignedBigInteger('requested_tokens')->default(0);
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->unsignedBigInteger('consumed_tokens')->default(0);
            $table->string('provider_job_id', 255)->nullable()->index();
            $table->json('input_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'ai_jobs_tenant_idempotency_unique');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
