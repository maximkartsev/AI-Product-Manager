<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->unique()->after('worker_id');
            $table->boolean('is_approved')->default(false)->index()->after('is_draining');
            $table->string('last_ip', 45)->nullable()->after('last_seen_at');
        });

        // Batch-approve existing workers that have been seen
        DB::connection($this->connection)
            ->table('comfy_ui_workers')
            ->whereNotNull('last_seen_at')
            ->update(['is_approved' => true]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropIndex(['is_approved']);
            $table->dropColumn(['token_hash', 'is_approved', 'last_ip']);
        });
    }
};
