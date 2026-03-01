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
        $tableExists = Schema::hasTable('record_tags');

        if (!$tableExists) {
            /**
             * tag_id
             * record_id
             */
            Schema::create('record_tags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tag_id');
                $table->unsignedBigInteger('record_id');
                $table->index('tag_id');
                $table->index('record_id');
                $table->unique(['tag_id', 'record_id']);
                $table->timestamps();
            });
        }

        // Best-effort FK creation: some local MariaDB/InnoDB setups fail online ALTER FK operations.
        try {
            Schema::table('record_tags', function (Blueprint $table) {
                $table->foreign('tag_id')->references('id')->on('tags');
            });
        } catch (\Throwable) {
            // Non-blocking for local/test environments.
        }

        try {
            Schema::table('record_tags', function (Blueprint $table) {
                $table->foreign('record_id')->references('id')->on('records');
            });
        } catch (\Throwable) {
            // Non-blocking for local/test environments.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_tags');
    }
};


