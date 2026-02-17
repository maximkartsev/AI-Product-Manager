<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->string('comfyui_workflow_path', 2048)->nullable();
            $table->json('properties')->nullable();
            $table->string('output_node_id', 64)->nullable();
            $table->string('output_extension', 16)->default('mp4');
            $table->string('output_mime_type', 255)->default('video/mp4');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflows');
    }
};
