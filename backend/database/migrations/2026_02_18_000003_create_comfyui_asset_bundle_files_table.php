<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_asset_bundle_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('asset_file_id');
            $table->string('target_path', 1024);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['bundle_id', 'asset_file_id']);
            $table->foreign('bundle_id')->references('id')->on('comfyui_asset_bundles')->onDelete('cascade');
            $table->foreign('asset_file_id')->references('id')->on('comfyui_asset_files')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_asset_bundle_files');
    }
};
