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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tier_id');
            $table->string('status')->default('active');
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('price', 10, 2);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('external_id')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('auto_renew')->default(true);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tier_id')->references('id')->on('tiers');
            $table->index('user_id');
            $table->index('tier_id');
            $table->index('status');
            $table->index('starts_at');
            $table->index('ends_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
