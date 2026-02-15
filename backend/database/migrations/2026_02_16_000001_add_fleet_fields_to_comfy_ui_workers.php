<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
            $table->string('registration_source', 20)->default('admin')->after('last_ip');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('comfy_ui_workers', function (Blueprint $table) {
            $table->dropColumn('registration_source');
        });
    }
};
