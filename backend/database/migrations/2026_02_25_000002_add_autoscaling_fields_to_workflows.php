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
            $table->string('workload_kind', 20)->nullable()->after('output_mime_type')->index();
            $table->string('work_units_property_key', 255)->nullable()->after('workload_kind');
            $table->unsignedInteger('slo_p95_wait_seconds')->nullable()->after('work_units_property_key');
            $table->decimal('slo_video_seconds_per_processing_second_p95', 10, 4)
                ->nullable()
                ->after('slo_p95_wait_seconds');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('workflows', function (Blueprint $table) {
            $table->dropIndex(['workload_kind']);
            $table->dropColumn([
                'workload_kind',
                'work_units_property_key',
                'slo_p95_wait_seconds',
                'slo_video_seconds_per_processing_second_p95',
            ]);
        });
    }
};
