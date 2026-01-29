<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_jobs', 'provider')) {
                $table->string('provider', 50)->default('local')->index()->after('effect_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            try {
                $table->dropIndex(['provider']);
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('ai_jobs', 'provider')) {
                $table->dropColumn('provider');
            }
        });
    }
};
