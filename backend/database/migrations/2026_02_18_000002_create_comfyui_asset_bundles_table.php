<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('comfyui_asset_bundles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->index();
            $table->string('bundle_id', 64)->unique();
            $table->string('s3_prefix', 512);
            $table->text('notes')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('active_staging_at')->nullable();
            $table->timestamp('active_production_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('created_by_email', 255)->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflows');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('comfyui_asset_bundles');
    }
};
