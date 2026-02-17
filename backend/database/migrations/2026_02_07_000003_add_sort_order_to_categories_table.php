<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('description');
                $table->index('sort_order', 'idx_categories_sort_order');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'sort_order')) {
                $table->dropIndex('idx_categories_sort_order');
                $table->dropColumn('sort_order');
            }
        });
    }
};
