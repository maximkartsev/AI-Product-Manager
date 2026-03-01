<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('effect_variant_bindings')) {
            Schema::connection($this->connection)->create('effect_variant_bindings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('effect_id')->index();
                $table->unsignedBigInteger('effect_revision_id')->index();
                $table->string('variant_id', 255)->index();
                $table->unsignedBigInteger('workflow_id')->nullable()->index();
                $table->unsignedBigInteger('execution_environment_id')->nullable()->index();
                $table->string('provider', 100)->nullable()->index();
                $table->string('stage', 32)->default('production')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedBigInteger('rollback_of_binding_id')->nullable()->index();
                $table->json('reason_json')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamp('applied_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('effect_variant_bindings');
    }
};

