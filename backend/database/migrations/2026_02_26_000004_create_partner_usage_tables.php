<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('partner_usage_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 255)->nullable()->index();
            $table->unsignedBigInteger('tenant_job_id')->nullable()->index();
            $table->unsignedBigInteger('dispatch_id')->nullable()->index();
            $table->unsignedBigInteger('workflow_id')->nullable()->index();
            $table->unsignedBigInteger('effect_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('worker_id', 255)->nullable()->index();
            $table->unsignedBigInteger('worker_session_id')->nullable()->index();
            $table->string('comfy_prompt_id', 255)->nullable()->index();
            $table->string('node_id', 128)->nullable();
            $table->string('node_class_type', 255)->nullable()->index();
            $table->string('node_display_name', 255)->nullable();
            $table->string('provider', 100)->default('unknown')->index();
            $table->string('model', 255)->nullable()->index();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('credits', 18, 6)->nullable();
            $table->decimal('cost_usd_reported', 18, 8)->nullable();
            $table->json('usage_json')->nullable();
            $table->json('ui_json')->nullable();
            $table->timestamps();

            $table->index(
                ['provider', 'node_class_type', 'model'],
                'partner_usage_events_provider_node_model_idx'
            );
        });

        Schema::connection($this->connection)->create('partner_usage_prices', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 100)->default('unknown');
            $table->string('node_class_type', 255);
            $table->string('model', 255)->default('');
            $table->decimal('usd_per_1m_input_tokens', 18, 8)->nullable();
            $table->decimal('usd_per_1m_output_tokens', 18, 8)->nullable();
            $table->decimal('usd_per_1m_total_tokens', 18, 8)->nullable();
            $table->decimal('usd_per_credit', 18, 8)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('sample_ui_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'node_class_type', 'model'],
                'partner_usage_prices_provider_node_model_unique'
            );
            $table->index(
                ['provider', 'node_class_type', 'model'],
                'partner_usage_prices_provider_node_model_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('partner_usage_prices');
        Schema::connection($this->connection)->dropIfExists('partner_usage_events');
    }
};
