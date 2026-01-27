<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('tenants', 'db_pool')) {
                $table->string('db_pool')->default('tenant_pool_1')->after('user_id');
            }

            $table->index('db_pool');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'db_pool')) {
                $table->dropIndex(['db_pool']);
                $table->dropColumn('db_pool');
            }

            // Drop unique before dropping the column.
            try {
                $table->dropUnique(['user_id']);
            } catch (\Throwable $e) {
                // ignore
            }

            if (Schema::hasColumn('tenants', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });
    }
};

