<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'admin_ui_settings')) {
                $table->json('admin_ui_settings')->nullable()->after('is_admin');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (!Schema::hasColumn('users', 'admin_ui_settings')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_ui_settings');
        });
    }
};
