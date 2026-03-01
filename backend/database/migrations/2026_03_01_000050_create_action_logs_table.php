<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('action_logs')) {
            Schema::connection($this->connection)->create('action_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event', 120)->index();
                $table->string('severity', 20)->index();
                $table->string('module', 80)->index();
                $table->string('telemetry_sink', 40)->nullable()->index();
                $table->text('message')->nullable();
                $table->json('economic_impact_json')->nullable();
                $table->json('operator_action_json')->nullable();
                $table->json('context_json')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('action_logs');
    }
};

