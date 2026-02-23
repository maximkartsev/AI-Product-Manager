<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('comfyui_gpu_fleets', function (Blueprint $table) {
            $table->string('template_slug', 128)
                ->default('gpu-default')
                ->after('slug');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('comfyui_gpu_fleets', function (Blueprint $table) {
            $table->dropColumn('template_slug');
        });
    }
};
