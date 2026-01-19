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
        Schema::create('gallery_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('featured_by')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_staff_pick')->default(false);
            $table->integer('views_count')->default(0);
            $table->integer('likes_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->dateTime('featured_at')->nullable();
            $table->dateTime('published_at')->nullable();

            $table->foreign('video_id')->references('id')->on('videos')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('featured_by')->references('id')->on('users')->onDelete('set null');
            $table->index('video_id');
            $table->index('user_id');
            $table->index('is_featured');
            $table->index('is_staff_pick');
            $table->index('published_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gallery_videos');
    }
};
