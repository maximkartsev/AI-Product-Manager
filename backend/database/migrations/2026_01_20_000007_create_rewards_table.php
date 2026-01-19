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
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('credits');
            $table->string('trigger_event');
            $table->decimal('value', 10, 2);
            $table->integer('credits_awarded')->default(0);
            $table->string('icon')->nullable();
            $table->string('badge_image')->nullable();
            $table->integer('points_required')->default(0);
            $table->integer('max_claims')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_recurring')->default(false);

            $table->index('slug');
            $table->index('type');
            $table->index('trigger_event');
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
        Schema::dropIfExists('rewards');
    }
};
