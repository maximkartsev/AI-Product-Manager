<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('videos')) {
            return;
        }

        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('effect_id')->nullable()->index();
            $table->unsignedBigInteger('original_file_id')->nullable()->index();
            $table->unsignedBigInteger('processed_file_id')->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->string('status', 50)->default('queued')->index();
            $table->boolean('is_public')->default(false)->index();
            $table->json('processing_details')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
