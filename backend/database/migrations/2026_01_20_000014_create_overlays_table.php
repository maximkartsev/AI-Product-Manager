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
        Schema::create('overlays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('image');
            $table->string('file_path');
            $table->string('preview_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('blend_mode')->default('normal');
            $table->decimal('opacity_default', 5, 2)->default(100);
            $table->json('position_default')->nullable();
            $table->json('scale_default')->nullable();
            $table->boolean('is_animated')->default(false);
            $table->integer('duration')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_premium')->default(false);

            $table->index('slug');
            $table->index('type');
            $table->index('is_active');
            $table->index('sort_order');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overlays');
    }
};
