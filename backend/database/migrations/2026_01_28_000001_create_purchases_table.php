<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('user_id')->constrained()->index();
            $table->unsignedBigInteger('package_id')->nullable()->index();
            $table->decimal('original_amount', 10, 2)->default(0.0);
            $table->decimal('applied_discount_amount', 10, 2)->default(0.0);
            $table->decimal('total_amount', 10, 2)->default(0.0);
            $table->string('status', 50)->default('pending')->index();
            $table->string('external_transaction_id')->nullable()->unique();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
