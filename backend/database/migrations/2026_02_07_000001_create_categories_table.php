<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('deleted_at', 'idx_categories_deleted_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        Schema::dropIfExists('categories');
    }
};
