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
            $table->string('stage', 32)
                ->default('production')
                ->index()
                ->after('workflow_id');
        });

        Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
            $table->string('stage', 32)
                ->default('production')
                ->index()
                ->after('capacity_type');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('ai_job_dispatches', function (Blueprint $table) {
            $table->dropColumn('stage');
        });

        Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
    }
};
