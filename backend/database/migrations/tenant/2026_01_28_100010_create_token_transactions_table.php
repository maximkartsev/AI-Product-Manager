<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->bigInteger('amount');
            $table->string('type', 50)->index();
            $table->unsignedBigInteger('purchase_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('provider_transaction_id')->index();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_transaction_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_transactions');
    }
};
