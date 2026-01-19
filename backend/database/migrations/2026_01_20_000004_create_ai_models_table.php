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
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version')->nullable();
            $table->text('description')->nullable();
            $table->string('provider')->nullable();
            $table->string('type')->default('image');
            $table->decimal('credits_per_use', 10, 2)->default(1);
            $table->integer('processing_time_estimate')->nullable();
            $table->json('input_requirements')->nullable();
            $table->json('output_specs')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_premium')->default(false);

            $table->index('slug');
            $table->index('type');
            $table->index('provider');
            $table->index('is_active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
