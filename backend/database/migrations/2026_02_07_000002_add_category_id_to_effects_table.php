<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            if (!Schema::hasColumn('effects', 'category_id')) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('description')
                    ->constrained('categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            if (Schema::hasColumn('effects', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }
        });
    }
};
