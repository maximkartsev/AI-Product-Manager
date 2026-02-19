<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_asset_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->index();
            $table->string('kind', 64);
            $table->string('original_filename', 512);
            $table->string('s3_key', 2048);
            $table->string('content_type', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 128)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workflow_id', 'kind']);
            $table->foreign('workflow_id')->references('id')->on('workflows');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_asset_files');
    }
};
