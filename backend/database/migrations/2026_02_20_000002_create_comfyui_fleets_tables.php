<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_gpu_fleets', function (Blueprint $table) {
            $table->id();
            $table->string('stage', 32);
            $table->string('slug', 128);
            $table->string('name', 255);
            $table->json('instance_types')->nullable();
            $table->unsignedInteger('max_size')->default(0);
            $table->unsignedInteger('warmup_seconds')->nullable();
            $table->unsignedInteger('backlog_target')->nullable();
            $table->unsignedInteger('scale_to_zero_minutes')->nullable();
            $table->string('ami_ssm_parameter', 255)->nullable();
            $table->unsignedBigInteger('active_bundle_id')->nullable();
            $table->string('active_bundle_s3_prefix', 512)->nullable();
            $table->timestamps();

            $table->unique(['stage', 'slug']);
            $table->index(['stage']);
            $table->foreign('active_bundle_id')->references('id')->on('comfyui_asset_bundles');
        });

        Schema::connection($this->connection)->create('comfyui_workflow_fleets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('fleet_id');
            $table->string('stage', 32);
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->string('assigned_by_email', 255)->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'stage']);
            $table->index(['fleet_id', 'stage']);
            $table->foreign('workflow_id')->references('id')->on('workflows');
            $table->foreign('fleet_id')->references('id')->on('comfyui_gpu_fleets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_workflow_fleets');
        Schema::connection($this->connection)->dropIfExists('comfyui_gpu_fleets');
    }
};
