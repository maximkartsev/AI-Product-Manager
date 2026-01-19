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
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->string('format')->default('mp4');
            $table->string('resolution')->default('1080p');
            $table->string('quality')->default('high');
            $table->integer('bitrate')->nullable();
            $table->decimal('fps', 6, 2)->nullable();
            $table->string('codec')->nullable();
            $table->string('status')->default('pending');
            $table->integer('progress')->default(0);
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('download_url')->nullable();
            $table->dateTime('download_expires_at')->nullable();
            $table->integer('download_count')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->foreign('video_id')->references('id')->on('videos')->onDelete('cascade');
            $table->foreign('file_id')->references('id')->on('files')->onDelete('set null');
            $table->index('video_id');
            $table->index('file_id');
            $table->index('status');
            $table->index('format');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
