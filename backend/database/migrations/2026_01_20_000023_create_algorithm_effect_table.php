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
        Schema::create('algorithm_effect', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('algorithm_id');
            $table->unsignedBigInteger('effect_id');
            $table->integer('sort_order')->default(0);
            $table->json('config')->nullable();

            $table->foreign('algorithm_id')->references('id')->on('algorithms')->onDelete('cascade');
            $table->foreign('effect_id')->references('id')->on('effects')->onDelete('cascade');
            $table->unique(['algorithm_id', 'effect_id']);
            $table->index('algorithm_id');
            $table->index('effect_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('algorithm_effect');
    }
};
