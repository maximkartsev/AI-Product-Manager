<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        return !empty($result);
    }

    public function up(): void
    {
        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_job_dispatches', 'provider')) {
                $table->string('provider', 50)->default('local')->index()->after('tenant_job_id');
            }
        });

        DB::table('ai_job_dispatches')
            ->whereNull('provider')
            ->update(['provider' => 'local']);

        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_provider_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('provider');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            try {
                $table->dropIndex(['provider']);
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('ai_job_dispatches', 'provider')) {
                $table->dropColumn('provider');
            }
        });
    }
};
