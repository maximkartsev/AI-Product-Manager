<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->index();
            $table->string('transaction_id')->unique();
            $table->string('status', 50)->default('pending')->index();
            $table->decimal('amount', 10, 2)->default(0.0);
            $table->string('currency', 3)->default('USD');
            $table->string('payment_gateway', 50)->index();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
