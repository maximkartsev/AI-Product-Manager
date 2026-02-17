<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        return !empty($result);
    }

    public function up(): void
    {
        if (!Schema::hasTable('payments')) {
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
        } else {
            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'purchase_id')) {
                    $table->unsignedBigInteger('purchase_id')->index();
                }
                if (!Schema::hasColumn('payments', 'transaction_id')) {
                    $table->string('transaction_id')->unique();
                }
                if (!Schema::hasColumn('payments', 'status')) {
                    $table->string('status', 50)->default('pending')->index();
                }
                if (!Schema::hasColumn('payments', 'amount')) {
                    $table->decimal('amount', 10, 2)->default(0.0);
                }
                if (!Schema::hasColumn('payments', 'currency')) {
                    $table->string('currency', 3)->default('USD');
                }
                if (!Schema::hasColumn('payments', 'payment_gateway')) {
                    $table->string('payment_gateway', 50)->index();
                }
                if (!Schema::hasColumn('payments', 'processed_at')) {
                    $table->timestamp('processed_at')->nullable();
                }
                if (!Schema::hasColumn('payments', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
                if (!Schema::hasColumn('payments', 'created_at')) {
                    $table->timestamps();
                }
            });

            if (!$this->indexExists('payments', 'payments_purchase_id_index')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->index('purchase_id');
                });
            }
            if (!$this->indexExists('payments', 'payments_status_index')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->index('status');
                });
            }
            if (!$this->indexExists('payments', 'payments_payment_gateway_index')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->index('payment_gateway');
                });
            }
            if (!$this->indexExists('payments', 'payments_transaction_id_unique')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->unique('transaction_id');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
