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
        /**
         * tag_id
         * record_id
         */
        Schema::create('record_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('record_id');

            $table->foreign('tag_id')->references('id')->on('tags');
            $table->foreign('record_id')->references('id')->on('records');
            $table->index('tag_id');
            $table->index('record_id');
            $table->unique(['tag_id', 'record_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_tags');
    }
};


