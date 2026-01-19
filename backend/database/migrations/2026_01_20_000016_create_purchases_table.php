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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('type')->default('package');
            $table->string('status')->default('pending');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('currency')->default('USD');
            $table->integer('credits_granted')->default(0);
            $table->string('invoice_number')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('refunded_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('set null');
            $table->foreign('discount_id')->references('id')->on('discounts')->onDelete('set null');
            $table->index('user_id');
            $table->index('package_id');
            $table->index('discount_id');
            $table->index('status');
            $table->index('type');
            $table->index('invoice_number');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
