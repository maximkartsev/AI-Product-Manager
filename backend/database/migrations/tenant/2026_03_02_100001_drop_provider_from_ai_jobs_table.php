<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_jobs') && Schema::hasColumn('ai_jobs', 'provider')) {
            Schema::table('ai_jobs', function (Blueprint $table) {
                try {
                    $table->dropIndex(['provider']);
                } catch (\Throwable) {
                    // best effort for environments with divergent index names/state
                }
            });

            Schema::table('ai_jobs', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_jobs') && !Schema::hasColumn('ai_jobs', 'provider')) {
            Schema::table('ai_jobs', function (Blueprint $table) {
                $table->string('provider', 50)->default('self_hosted')->index()->after('effect_id');
            });
        }
    }
};
