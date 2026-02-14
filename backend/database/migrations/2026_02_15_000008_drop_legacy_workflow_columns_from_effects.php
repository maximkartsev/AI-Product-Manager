<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        // Safety check: ensure all effects have workflow_id set
        $orphaned = DB::connection($this->connection)
            ->table('effects')
            ->whereNull('workflow_id')
            ->whereNotNull('comfyui_workflow_path')
            ->whereNull('deleted_at')
            ->count();

        if ($orphaned > 0) {
            throw new \RuntimeException(
                "Cannot drop legacy columns: {$orphaned} active effect(s) still have comfyui_workflow_path but no workflow_id. Run the data migration first."
            );
        }

        Schema::connection($this->connection)->table('effects', function (Blueprint $table) {
            $table->dropColumn([
                'comfyui_workflow_path',
                'comfyui_input_path_placeholder',
                'output_extension',
                'output_mime_type',
                'output_node_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('effects', function (Blueprint $table) {
            $table->string('comfyui_workflow_path', 2048)->nullable();
            $table->string('comfyui_input_path_placeholder', 255)->nullable();
            $table->string('output_extension', 16)->nullable();
            $table->string('output_mime_type', 255)->nullable();
            $table->string('output_node_id', 64)->nullable();
        });
    }
};
