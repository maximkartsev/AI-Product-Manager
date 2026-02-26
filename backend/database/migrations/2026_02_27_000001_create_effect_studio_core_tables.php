<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('effect_revisions')) {
            Schema::connection($this->connection)->create('effect_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('effect_id')->index();
                $table->unsignedBigInteger('workflow_id')->nullable()->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->string('publication_status', 32)->nullable()->index();
                $table->json('property_overrides')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->unsignedBigInteger('recommended_execution_environment_id')->nullable()->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->index(['effect_id', 'created_at'], 'effect_revisions_effect_created_idx');
            });
        }

        if (!Schema::connection($this->connection)->hasTable('workflow_revisions')) {
            Schema::connection($this->connection)->create('workflow_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workflow_id')->index();
                $table->string('comfyui_workflow_path', 2048)->nullable();
                $table->json('snapshot_json')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->index(['workflow_id', 'created_at'], 'workflow_revisions_workflow_created_idx');
            });
        }

        if (!Schema::connection($this->connection)->hasTable('dev_nodes')) {
            Schema::connection($this->connection)->create('dev_nodes', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('instance_type', 64)->nullable();
                $table->string('stage', 32)->default('dev')->index();
                $table->string('lifecycle', 32)->default('on-demand')->index();
                $table->string('status', 32)->default('stopped')->index();
                $table->string('aws_instance_id', 128)->nullable()->index();
                $table->string('public_endpoint', 2048)->nullable();
                $table->string('private_endpoint', 2048)->nullable();
                $table->string('active_bundle_ref', 255)->nullable();
                $table->unsignedBigInteger('assigned_to_user_id')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('ready_at')->nullable()->index();
                $table->timestamp('ended_at')->nullable()->index();
                $table->timestamp('last_activity_at')->nullable()->index();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('execution_environments')) {
            Schema::connection($this->connection)->create('execution_environments', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('kind', 32)->index(); // dev_node | test_asg | prod_asg
                $table->string('stage', 32)->index(); // dev | test | production
                $table->string('fleet_slug', 255)->nullable()->index();
                $table->unsignedBigInteger('dev_node_id')->nullable()->index();
                $table->json('configuration_json')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['kind', 'stage'], 'execution_environments_kind_stage_idx');
            });
        }

        if (!Schema::connection($this->connection)->hasTable('workflow_analysis_jobs')) {
            Schema::connection($this->connection)->create('workflow_analysis_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workflow_id')->nullable()->index();
                $table->string('status', 32)->default('pending')->index();
                $table->string('analyzer_prompt_version', 32);
                $table->string('analyzer_schema_version', 32);
                $table->string('requested_output_kind', 32)->nullable();
                $table->json('input_json')->nullable();
                $table->json('result_json')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::connection($this->connection)->hasTable('effects')) {
            $hasPublishedRevisionId = Schema::connection($this->connection)
                ->hasColumn('effects', 'published_revision_id');
            $hasProdExecutionEnvironmentId = Schema::connection($this->connection)
                ->hasColumn('effects', 'prod_execution_environment_id');

            if (!$hasPublishedRevisionId || !$hasProdExecutionEnvironmentId) {
                Schema::connection($this->connection)->table('effects', function (Blueprint $table) use ($hasPublishedRevisionId, $hasProdExecutionEnvironmentId) {
                    if (!$hasPublishedRevisionId) {
                        $table->unsignedBigInteger('published_revision_id')->nullable()->index()->after('publication_status');
                    }
                    if (!$hasProdExecutionEnvironmentId) {
                        $table->unsignedBigInteger('prod_execution_environment_id')->nullable()->index()->after('published_revision_id');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('effects')) {
            $hasPublishedRevisionId = Schema::connection($this->connection)
                ->hasColumn('effects', 'published_revision_id');
            $hasProdExecutionEnvironmentId = Schema::connection($this->connection)
                ->hasColumn('effects', 'prod_execution_environment_id');

            if ($hasProdExecutionEnvironmentId || $hasPublishedRevisionId) {
                Schema::connection($this->connection)->table('effects', function (Blueprint $table) use ($hasPublishedRevisionId, $hasProdExecutionEnvironmentId) {
                    if ($hasProdExecutionEnvironmentId) {
                        $table->dropColumn('prod_execution_environment_id');
                    }
                    if ($hasPublishedRevisionId) {
                        $table->dropColumn('published_revision_id');
                    }
                });
            }
        }

        Schema::connection($this->connection)->dropIfExists('workflow_analysis_jobs');
        Schema::connection($this->connection)->dropIfExists('execution_environments');
        Schema::connection($this->connection)->dropIfExists('dev_nodes');
        Schema::connection($this->connection)->dropIfExists('workflow_revisions');
        Schema::connection($this->connection)->dropIfExists('effect_revisions');
    }
};
