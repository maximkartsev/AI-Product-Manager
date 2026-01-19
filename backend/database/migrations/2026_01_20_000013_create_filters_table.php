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
        Schema::create('filters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('color');
            $table->string('preview_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->json('adjustments')->nullable();
            $table->string('lut_file')->nullable();
            $table->decimal('intensity_min', 5, 2)->default(0);
            $table->decimal('intensity_max', 5, 2)->default(100);
            $table->decimal('intensity_default', 5, 2)->default(100);
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
        Schema::dropIfExists('filters');
    }
};
