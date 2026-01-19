<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('credits_balance', 12, 2)->default(0)->after('password');
            $table->string('avatar_url')->nullable()->after('credits_balance');
            $table->string('timezone')->default('UTC')->after('avatar_url');
            $table->string('locale')->default('en')->after('timezone');
            $table->json('preferences')->nullable()->after('locale');
            $table->string('referral_code')->nullable()->unique()->after('preferences');
            $table->unsignedBigInteger('referred_by')->nullable()->after('referral_code');
            $table->integer('referral_count')->default(0)->after('referred_by');

            $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
            $table->index('referral_code');
            $table->index('referred_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropColumn([
                'credits_balance',
                'avatar_url',
                'timezone',
                'locale',
                'preferences',
                'referral_code',
                'referred_by',
                'referral_count'
            ]);
        });
    }
};
