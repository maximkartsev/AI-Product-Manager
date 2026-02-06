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
            if (!Schema::hasColumn('effects', 'last_processing_time_seconds')) {
                $table->integer('last_processing_time_seconds')->nullable()->after('processing_time_estimate');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            if (Schema::hasColumn('effects', 'last_processing_time_seconds')) {
                $table->dropColumn('last_processing_time_seconds');
            }
        });
    }
};
