<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('personal_access_tokens', 'device_name')) {
                $table->string('device_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('personal_access_tokens', 'device_type')) {
                $table->string('device_type')->nullable()->after('device_name');
            }
            if (!Schema::hasColumn('personal_access_tokens', 'browser')) {
                $table->string('browser')->nullable()->after('device_type');
            }
            if (!Schema::hasColumn('personal_access_tokens', 'platform')) {
                $table->string('platform')->nullable()->after('browser');
            }
            if (!Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $cols = [];
            foreach (['device_name', 'device_type', 'browser', 'platform', 'ip_address'] as $c) {
                if (Schema::hasColumn('personal_access_tokens', $c)) {
                    $cols[] = $c;
                }
            }

            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

