<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comfyui_workers') && !Schema::hasTable('comfy_ui_workers')) {
            Schema::rename('comfyui_workers', 'comfy_ui_workers');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('comfy_ui_workers') && !Schema::hasTable('comfyui_workers')) {
            Schema::rename('comfy_ui_workers', 'comfyui_workers');
        }
    }
};
