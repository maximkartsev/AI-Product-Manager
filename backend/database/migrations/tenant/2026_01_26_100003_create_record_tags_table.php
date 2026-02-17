<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_tags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('record_id');
            $table->timestamps();

            $table->index(['tenant_id', 'tag_id']);
            $table->index(['tenant_id', 'record_id']);
            $table->unique(['tenant_id', 'tag_id', 'record_id']);

            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->foreign('record_id')->references('id')->on('records')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_tags');
    }
};

