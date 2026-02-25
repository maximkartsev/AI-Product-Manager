<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_worker_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('worker_id')->nullable()->index();
            $table->string('worker_identifier', 255)->nullable()->index();
            $table->string('fleet_slug', 255)->nullable()->index();
            $table->string('stage', 50)->nullable()->index();
            $table->string('instance_type', 64)->nullable();
            $table->string('lifecycle', 32)->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->unsignedBigInteger('busy_seconds')->default(0);
            $table->unsignedBigInteger('running_seconds')->default(0);
            $table->decimal('utilization', 6, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_worker_sessions');
    }
};
