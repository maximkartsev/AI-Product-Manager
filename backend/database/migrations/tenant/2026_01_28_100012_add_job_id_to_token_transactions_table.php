<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('token_transactions', 'job_id')) {
                $table->unsignedBigInteger('job_id')->nullable()->after('payment_id');
            }
        });

        Schema::table('token_transactions', function (Blueprint $table) {
            try {
                $table->index('job_id');
            } catch (\Throwable $e) {
                // ignore if already exists
            }

            try {
                $table->unique(
                    ['tenant_id', 'job_id', 'type'],
                    'token_transactions_tenant_job_type_unique'
                );
            } catch (\Throwable $e) {
                // ignore if already exists
            }
        });
    }

    public function down(): void
    {
        Schema::table('token_transactions', function (Blueprint $table) {
            try {
                $table->dropUnique('token_transactions_tenant_job_type_unique');
            } catch (\Throwable $e) {
                // ignore
            }

            try {
                $table->dropIndex(['job_id']);
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('token_transactions', 'job_id')) {
                $table->dropColumn('job_id');
            }
        });
    }
};
