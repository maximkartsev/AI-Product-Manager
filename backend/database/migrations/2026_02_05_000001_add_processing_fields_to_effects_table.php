<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            if (!Schema::hasColumn('effects', 'type')) {
                $table->string('type', 255)->default('transform')->after('description');
            }
            if (!Schema::hasColumn('effects', 'preview_url')) {
                $table->string('preview_url', 2048)->nullable()->after('type');
            }
            if (!Schema::hasColumn('effects', 'parameters')) {
                $table->text('parameters')->nullable()->after('preview_url');
            }
            if (!Schema::hasColumn('effects', 'default_values')) {
                $table->text('default_values')->nullable()->after('parameters');
            }
            if (!Schema::hasColumn('effects', 'credits_cost')) {
                $table->decimal('credits_cost', 8, 2)->default(1)->after('default_values');
            }
            if (!Schema::hasColumn('effects', 'processing_time_estimate')) {
                $table->integer('processing_time_estimate')->nullable()->after('credits_cost');
            }
            if (!Schema::hasColumn('effects', 'popularity_score')) {
                $table->integer('popularity_score')->default(0)->after('processing_time_estimate');
            }
            if (!Schema::hasColumn('effects', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('popularity_score');
            }
            if (!Schema::hasColumn('effects', 'is_new')) {
                $table->boolean('is_new')->default(false)->after('is_premium');
            }
            if (!Schema::hasColumn('effects', 'comfyui_workflow_path')) {
                $table->string('comfyui_workflow_path', 2048)
                    ->default('resources/comfyui/workflows/cloud_video_effect.json')
                    ->after('preview_video_url');
            }
            if (!Schema::hasColumn('effects', 'comfyui_input_path_placeholder')) {
                $table->string('comfyui_input_path_placeholder', 255)
                    ->default('__INPUT_PATH__')
                    ->after('comfyui_workflow_path');
            }
            if (!Schema::hasColumn('effects', 'output_extension')) {
                $table->string('output_extension', 16)->default('mp4')->after('comfyui_input_path_placeholder');
            }
            if (!Schema::hasColumn('effects', 'output_mime_type')) {
                $table->string('output_mime_type', 255)->default('video/mp4')->after('output_extension');
            }
            if (!Schema::hasColumn('effects', 'output_node_id')) {
                $table->string('output_node_id', 64)->default('3')->after('output_mime_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('effects')) {
            return;
        }

        Schema::table('effects', function (Blueprint $table) {
            $columns = [
                'type',
                'preview_url',
                'parameters',
                'default_values',
                'credits_cost',
                'processing_time_estimate',
                'popularity_score',
                'sort_order',
                'is_new',
                'comfyui_workflow_path',
                'comfyui_input_path_placeholder',
                'output_extension',
                'output_mime_type',
                'output_node_id',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('effects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
