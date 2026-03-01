<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('partner_usage_events', function (Blueprint $table) {
            if (!Schema::connection($this->connection)->hasColumn('partner_usage_events', 'load_test_run_id')) {
                $table->unsignedBigInteger('load_test_run_id')
                    ->nullable()
                    ->after('dispatch_id')
                    ->index();
            }

            if (!Schema::connection($this->connection)->hasColumn('partner_usage_events', 'benchmark_context_id')) {
                $table->string('benchmark_context_id', 120)
                    ->nullable()
                    ->after('load_test_run_id')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('partner_usage_events', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('partner_usage_events', 'benchmark_context_id')) {
                $table->dropIndex(['benchmark_context_id']);
                $table->dropColumn('benchmark_context_id');
            }

            if (Schema::connection($this->connection)->hasColumn('partner_usage_events', 'load_test_run_id')) {
                $table->dropIndex(['load_test_run_id']);
                $table->dropColumn('load_test_run_id');
            }
        });
    }
};

