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
            $table->unsignedBigInteger('workflow_id')->nullable()->index()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('ai_job_dispatches', function (Blueprint $table) {
            $table->dropIndex(['workflow_id']);
            $table->dropColumn('workflow_id');
        });
    }
};
