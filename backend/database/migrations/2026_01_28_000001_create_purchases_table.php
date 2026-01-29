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
        if (!Schema::hasTable('purchases')) {
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
        } else {
            Schema::table('purchases', function (Blueprint $table) {
                if (!Schema::hasColumn('purchases', 'tenant_id')) {
                    $table->string('tenant_id')->index();
                }
                if (!Schema::hasColumn('purchases', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->index();
                }
                if (!Schema::hasColumn('purchases', 'package_id')) {
                    $table->unsignedBigInteger('package_id')->nullable()->index();
                }
                if (!Schema::hasColumn('purchases', 'original_amount')) {
                    $table->decimal('original_amount', 10, 2)->default(0.0);
                }
                if (!Schema::hasColumn('purchases', 'applied_discount_amount')) {
                    $table->decimal('applied_discount_amount', 10, 2)->default(0.0);
                }
                if (!Schema::hasColumn('purchases', 'total_amount')) {
                    $table->decimal('total_amount', 10, 2)->default(0.0);
                }
                if (!Schema::hasColumn('purchases', 'status')) {
                    $table->string('status', 50)->default('pending')->index();
                }
                if (!Schema::hasColumn('purchases', 'external_transaction_id')) {
                    $table->string('external_transaction_id')->nullable();
                }
                if (!Schema::hasColumn('purchases', 'processed_at')) {
                    $table->timestamp('processed_at')->nullable();
                }
                if (!Schema::hasColumn('purchases', 'created_at')) {
                    $table->timestamps();
                }
            });

            Schema::table('purchases', function (Blueprint $table) {
                // Placeholder to keep migration structure (indexes added below).
            });

            if (!$this->indexExists('purchases', 'purchases_tenant_id_index')) {
                Schema::table('purchases', function (Blueprint $table) {
                    $table->index('tenant_id');
                });
            }
            if (!$this->indexExists('purchases', 'purchases_user_id_index')) {
                Schema::table('purchases', function (Blueprint $table) {
                    $table->index('user_id');
                });
            }
            if (!$this->indexExists('purchases', 'purchases_package_id_index')) {
                Schema::table('purchases', function (Blueprint $table) {
                    $table->index('package_id');
                });
            }
            if (!$this->indexExists('purchases', 'purchases_status_index')) {
                Schema::table('purchases', function (Blueprint $table) {
                    $table->index('status');
                });
            }
            if (!$this->indexExists('purchases', 'purchases_external_transaction_id_unique')) {
                Schema::table('purchases', function (Blueprint $table) {
                    $table->unique('external_transaction_id');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
