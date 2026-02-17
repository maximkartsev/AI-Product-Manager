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
         * user_id
         * commit_id
         * date
         */
        Schema::create('rollouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('commit_id');
            $table->date('date');

            $table->foreign('user_id')->references('id')->on('users');
            $table->index('user_id');
            $table->index('date');
            $table->index('commit_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rollouts');
    }
};


