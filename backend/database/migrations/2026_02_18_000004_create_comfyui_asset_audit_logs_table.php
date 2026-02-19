<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_asset_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_id')->nullable();
            $table->unsignedBigInteger('asset_file_id')->nullable();
            $table->string('event', 64);
            $table->text('notes')->nullable();
            $table->string('artifact_s3_key', 1024)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_email', 255)->nullable();
            $table->timestamp('created_at');

            $table->index(['event', 'created_at']);
            $table->foreign('bundle_id')->references('id')->on('comfyui_asset_bundles')->onDelete('set null');
            $table->foreign('asset_file_id')->references('id')->on('comfyui_asset_files')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_asset_audit_logs');
    }
};
