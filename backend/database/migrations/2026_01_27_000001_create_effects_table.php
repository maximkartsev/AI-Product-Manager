<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This repo may be used against long-lived local DBs. Be safe if the table already exists.
        if (Schema::hasTable('effects')) {
            return;
        }

        Schema::create('effects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('preview_video_url', 2048)->nullable();
            $table->boolean('is_premium')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('deleted_at', 'idx_effects_deleted_at');
        });
    }

    public function down(): void
    {
        // This migration may have been applied to an already-existing table.
        // Avoid dropping the whole table on rollback in that scenario.
        if (!Schema::hasTable('effects')) {
            return;
        }

        // Intentionally no-op (safe rollback).
    }
};

