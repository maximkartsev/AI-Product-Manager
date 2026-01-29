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
        if (!Schema::hasTable('comfyui_workers')) {
            Schema::create('comfyui_workers', function (Blueprint $table) {
                $table->id();
                $table->string('worker_id', 255)->unique();
                $table->string('display_name', 255)->nullable();
                $table->string('environment', 50)->default('cloud')->index();
                $table->json('capabilities')->nullable();
                $table->unsignedInteger('max_concurrency')->default(1);
                $table->unsignedInteger('current_load')->default(0);
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->boolean('is_draining')->default(false)->index();
                $table->timestamps();
            });

            return;
        }

        Schema::table('comfyui_workers', function (Blueprint $table) {
            if (!Schema::hasColumn('comfyui_workers', 'worker_id')) {
                $table->string('worker_id', 255)->unique();
            }
            if (!Schema::hasColumn('comfyui_workers', 'display_name')) {
                $table->string('display_name', 255)->nullable();
            }
            if (!Schema::hasColumn('comfyui_workers', 'environment')) {
                $table->string('environment', 50)->default('cloud')->index();
            }
            if (!Schema::hasColumn('comfyui_workers', 'capabilities')) {
                $table->json('capabilities')->nullable();
            }
            if (!Schema::hasColumn('comfyui_workers', 'max_concurrency')) {
                $table->unsignedInteger('max_concurrency')->default(1);
            }
            if (!Schema::hasColumn('comfyui_workers', 'current_load')) {
                $table->unsignedInteger('current_load')->default(0);
            }
            if (!Schema::hasColumn('comfyui_workers', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->index();
            }
            if (!Schema::hasColumn('comfyui_workers', 'is_draining')) {
                $table->boolean('is_draining')->default(false)->index();
            }
            if (!Schema::hasColumn('comfyui_workers', 'created_at')) {
                $table->timestamps();
            }
        });

        if (!$this->indexExists('comfyui_workers', 'comfyui_workers_worker_id_unique')) {
            Schema::table('comfyui_workers', function (Blueprint $table) {
                $table->unique('worker_id');
            });
        }
        if (!$this->indexExists('comfyui_workers', 'comfyui_workers_environment_index')) {
            Schema::table('comfyui_workers', function (Blueprint $table) {
                $table->index('environment');
            });
        }
        if (!$this->indexExists('comfyui_workers', 'comfyui_workers_last_seen_at_index')) {
            Schema::table('comfyui_workers', function (Blueprint $table) {
                $table->index('last_seen_at');
            });
        }
        if (!$this->indexExists('comfyui_workers', 'comfyui_workers_is_draining_index')) {
            Schema::table('comfyui_workers', function (Blueprint $table) {
                $table->index('is_draining');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comfyui_workers');
    }
};
