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
        Schema::create('effects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('ai_model_id')->nullable();
            $table->string('type')->default('transform');
            $table->string('preview_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->json('parameters')->nullable();
            $table->json('default_values')->nullable();
            $table->decimal('credits_cost', 10, 2)->default(1);
            $table->integer('processing_time_estimate')->nullable();
            $table->integer('popularity_score')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_new')->default(false);

            $table->foreign('ai_model_id')->references('id')->on('ai_models')->onDelete('set null');
            $table->index('ai_model_id');
            $table->index('slug');
            $table->index('type');
            $table->index('is_active');
            $table->index('is_premium');
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
        Schema::dropIfExists('effects');
    }
};
