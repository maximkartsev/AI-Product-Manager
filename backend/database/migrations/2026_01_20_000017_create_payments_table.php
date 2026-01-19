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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->string('provider')->default('stripe');
            $table->string('method')->default('card');
            $table->string('status')->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('currency')->default('USD');
            $table->string('external_id')->nullable();
            $table->string('external_status')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('refunded_at')->nullable();

            $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
            $table->index('purchase_id');
            $table->index('provider');
            $table->index('status');
            $table->index('external_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
