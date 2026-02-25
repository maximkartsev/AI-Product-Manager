<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            $table->timestamp('leased_at')->nullable()->after('lease_expires_at')->index();
            $table->timestamp('last_leased_at')->nullable()->after('leased_at')->index();
            $table->timestamp('finished_at')->nullable()->after('last_leased_at')->index();
            $table->unsignedInteger('processing_seconds')->nullable()->after('finished_at');
            $table->unsignedInteger('queue_wait_seconds')->nullable()->after('processing_seconds');
            $table->decimal('work_units', 10, 4)->nullable()->after('queue_wait_seconds');
            $table->string('work_unit_kind', 50)->nullable()->after('work_units')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            $table->dropIndex(['leased_at']);
            $table->dropIndex(['last_leased_at']);
            $table->dropIndex(['finished_at']);
            $table->dropIndex(['work_unit_kind']);
            $table->dropColumn([
                'leased_at',
                'last_leased_at',
                'finished_at',
                'processing_seconds',
                'queue_wait_seconds',
                'work_units',
                'work_unit_kind',
            ]);
        });
    }
};
