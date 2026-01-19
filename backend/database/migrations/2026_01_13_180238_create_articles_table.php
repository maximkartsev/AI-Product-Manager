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
         * title
         * user_id
         * sub_title
         * state
         * content
         * published_at
         */
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('user_id');
            $table->string('sub_title')->nullable();
            $table->string('state')->default('draft');
            $table->longText('content')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index('state');
            $table->index('published_at');
            $table->index('title');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
