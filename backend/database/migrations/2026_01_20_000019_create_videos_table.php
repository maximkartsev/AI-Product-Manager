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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_file_id');
            $table->unsignedBigInteger('effect_id')->nullable();
            $table->unsignedBigInteger('style_id')->nullable();
            $table->unsignedBigInteger('filter_id')->nullable();
            $table->unsignedBigInteger('overlay_id')->nullable();
            $table->unsignedBigInteger('watermark_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->json('effect_parameters')->nullable();
            $table->json('style_parameters')->nullable();
            $table->json('filter_parameters')->nullable();
            $table->json('overlay_parameters')->nullable();
            $table->json('watermark_parameters')->nullable();
            $table->json('timeline')->nullable();
            $table->decimal('credits_used', 10, 2)->default(0);
            $table->integer('processing_time')->nullable();
            $table->string('preview_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->integer('views_count')->default(0);
            $table->integer('likes_count')->default(0);
            $table->boolean('is_public')->default(false);
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processing_completed_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('source_file_id')->references('id')->on('files');
            $table->foreign('effect_id')->references('id')->on('effects')->onDelete('set null');
            $table->foreign('style_id')->references('id')->on('styles')->onDelete('set null');
            $table->foreign('filter_id')->references('id')->on('filters')->onDelete('set null');
            $table->foreign('overlay_id')->references('id')->on('overlays')->onDelete('set null');
            $table->foreign('watermark_id')->references('id')->on('watermarks')->onDelete('set null');
            $table->index('user_id');
            $table->index('source_file_id');
            $table->index('effect_id');
            $table->index('style_id');
            $table->index('filter_id');
            $table->index('status');
            $table->index('is_public');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
