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
            if (Schema::hasColumn('effects', 'category_id')) {
                $table->index(['category_id', 'id'], 'idx_effects_category_latest');
            }
            if (Schema::hasColumn('effects', 'popularity_score')) {
                $table->index(['is_active', 'popularity_score'], 'idx_effects_active_popularity');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            $table->dropIndex('idx_effects_category_latest');
            $table->dropIndex('idx_effects_active_popularity');
        });
    }
};
