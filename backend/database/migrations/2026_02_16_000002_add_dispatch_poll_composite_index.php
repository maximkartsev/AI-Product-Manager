<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('ai_job_dispatches', function (Blueprint $table) {
            $table->index(
                ['workflow_id', 'status', 'attempts', 'priority', 'created_at'],
                'idx_dispatch_poll'
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('ai_job_dispatches', function (Blueprint $table) {
            $table->dropIndex('idx_dispatch_poll');
        });
    }
};
