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
            if (Schema::hasColumn('users', 'credit_balance')) {
                $table->dropColumn('credit_balance');
            }
            if (Schema::hasColumn('users', 'creadit_balance')) {
                $table->dropColumn('creadit_balance');
            }
            if (Schema::hasColumn('users', 'discount_balance')) {
                $table->dropColumn('discount_balance');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'discount_balance')) {
                $table->decimal('discount_balance', 10, 2)->default(0.0);
            }
        });
    }
};
