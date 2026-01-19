<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('mime_type')->nullable();
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('type')->default('video');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('duration')->nullable();
            $table->decimal('fps', 6, 2)->nullable();
            $table->string('codec')->nullable();
            $table->integer('bitrate')->nullable();
            $table->json('metadata')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('status')->default('pending');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id');
            $table->index('type');
            $table->index('status');
            $table->index('mime_type');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
