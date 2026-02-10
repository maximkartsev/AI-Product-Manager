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
            $columns = [
                'preview_url',
                'parameters',
                'default_values',
                'processing_time_estimate',
                'sort_order',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('effects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            if (!Schema::hasColumn('effects', 'preview_url')) {
                $table->string('preview_url', 2048)->nullable()->after('type');
            }
            if (!Schema::hasColumn('effects', 'parameters')) {
                $table->text('parameters')->nullable()->after('preview_url');
            }
            if (!Schema::hasColumn('effects', 'default_values')) {
                $table->text('default_values')->nullable()->after('parameters');
            }
            if (!Schema::hasColumn('effects', 'processing_time_estimate')) {
                $table->integer('processing_time_estimate')->nullable()->after('credits_cost');
            }
            if (!Schema::hasColumn('effects', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('popularity_score');
            }
        });
    }
};
