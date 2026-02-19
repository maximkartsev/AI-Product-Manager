<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_workflow_active_bundles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->index();
            $table->string('stage', 32);
            $table->unsignedBigInteger('bundle_id');
            $table->string('bundle_s3_prefix', 512);
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by_user_id')->nullable();
            $table->string('activated_by_email', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'stage']);
            $table->foreign('workflow_id')->references('id')->on('workflows');
            $table->foreign('bundle_id')->references('id')->on('comfyui_asset_bundles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_workflow_active_bundles');
    }
};
