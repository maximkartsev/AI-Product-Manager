<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('extension')->nullable();
            $table->string('storage')->nullable();
            $table->string('entity_table')->nullable();
            $table->integer('entity_id')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_table', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_files');
    }
};

