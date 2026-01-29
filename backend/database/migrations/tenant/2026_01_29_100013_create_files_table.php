<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('files')) {
            return;
        }

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('disk', 50)->default('s3');
            $table->string('path', 1024);
            $table->string('url', 2048)->nullable();
            $table->string('mime_type', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('original_filename', 512)->nullable();
            $table->string('file_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'path']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
