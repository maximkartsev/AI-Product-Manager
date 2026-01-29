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
        if (!Schema::hasTable('ai_job_dispatches')) {
            Schema::create('ai_job_dispatches', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id')->index();
                $table->unsignedBigInteger('tenant_job_id')->index();
                $table->string('status', 50)->default('queued')->index();
                $table->integer('priority')->default(0)->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('worker_id')->nullable()->index();
                $table->string('lease_token', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable()->index();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'tenant_job_id'], 'ai_job_dispatches_tenant_job_unique');
            });

            return;
        }

        Schema::table('ai_job_dispatches', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_job_dispatches', 'tenant_id')) {
                $table->string('tenant_id')->index();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'tenant_job_id')) {
                $table->unsignedBigInteger('tenant_job_id')->index();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'status')) {
                $table->string('status', 50)->default('queued')->index();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'priority')) {
                $table->integer('priority')->default(0)->index();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'attempts')) {
                $table->unsignedInteger('attempts')->default(0);
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'worker_id')) {
                $table->string('worker_id')->nullable()->index();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'lease_token')) {
                $table->string('lease_token', 64)->nullable();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'lease_expires_at')) {
                $table->timestamp('lease_expires_at')->nullable()->index();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (!Schema::hasColumn('ai_job_dispatches', 'created_at')) {
                $table->timestamps();
            }
        });

        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_tenant_id_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('tenant_id');
            });
        }
        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_tenant_job_id_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('tenant_job_id');
            });
        }
        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_status_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('status');
            });
        }
        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_priority_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('priority');
            });
        }
        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_worker_id_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('worker_id');
            });
        }
        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_lease_expires_at_index')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->index('lease_expires_at');
            });
        }
        if (!$this->indexExists('ai_job_dispatches', 'ai_job_dispatches_tenant_job_unique')) {
            Schema::table('ai_job_dispatches', function (Blueprint $table) {
                $table->unique(['tenant_id', 'tenant_job_id'], 'ai_job_dispatches_tenant_job_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_job_dispatches');
    }
};
