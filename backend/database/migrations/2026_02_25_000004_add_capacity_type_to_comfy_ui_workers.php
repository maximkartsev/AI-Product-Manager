<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comfy_ui_workers', function (Blueprint $table) {
            $table->string('capacity_type', 20)->nullable()->after('registration_source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('comfy_ui_workers', function (Blueprint $table) {
            $table->dropIndex(['capacity_type']);
            $table->dropColumn('capacity_type');
        });
    }
};
