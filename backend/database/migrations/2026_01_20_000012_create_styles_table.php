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
        Schema::create('styles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('effect_id');
            $table->string('preview_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->json('parameters')->nullable();
            $table->decimal('intensity_min', 5, 2)->default(0);
            $table->decimal('intensity_max', 5, 2)->default(100);
            $table->decimal('intensity_default', 5, 2)->default(50);
            $table->decimal('credits_modifier', 5, 2)->default(1);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_premium')->default(false);

            $table->foreign('effect_id')->references('id')->on('effects')->onDelete('cascade');
            $table->index('effect_id');
            $table->index('slug');
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
        Schema::dropIfExists('styles');
    }
};
