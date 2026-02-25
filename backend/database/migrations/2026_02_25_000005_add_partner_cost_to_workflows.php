<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('workflows', function (Blueprint $table) {
            $table->decimal('partner_cost_per_work_unit', 10, 4)
                ->nullable()
                ->after('slo_video_seconds_per_processing_second_p95');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('workflows', function (Blueprint $table) {
            $table->dropColumn('partner_cost_per_work_unit');
        });
    }
};
