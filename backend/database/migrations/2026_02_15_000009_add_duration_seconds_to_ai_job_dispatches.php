<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            $table->unsignedInteger('duration_seconds')->nullable()->after('last_error');
        });

        // Add index on worker_audit_logs.dispatch_id for efficient joins
        Schema::table('worker_audit_logs', function (Blueprint $table) {
            $table->index('dispatch_id', 'worker_audit_logs_dispatch_id_index');
        });

        // Backfill existing completed dispatches from audit logs
        DB::statement("
            UPDATE ai_job_dispatches d
            JOIN (
                SELECT dispatch_id, MIN(created_at) as t
                FROM worker_audit_logs
                WHERE event = 'poll' AND dispatch_id IS NOT NULL
                GROUP BY dispatch_id
            ) p ON p.dispatch_id = d.id
            JOIN (
                SELECT dispatch_id, MAX(created_at) as t
                FROM worker_audit_logs
                WHERE event = 'complete' AND dispatch_id IS NOT NULL
                GROUP BY dispatch_id
            ) c ON c.dispatch_id = d.id
            SET d.duration_seconds = TIMESTAMPDIFF(SECOND, p.t, c.t)
            WHERE d.status = 'completed' AND d.duration_seconds IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            $table->dropColumn('duration_seconds');
        });

        Schema::table('worker_audit_logs', function (Blueprint $table) {
            $table->dropIndex('worker_audit_logs_dispatch_id_index');
        });
    }
};
