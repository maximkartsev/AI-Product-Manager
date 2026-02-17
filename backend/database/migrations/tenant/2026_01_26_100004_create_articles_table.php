<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('title');
            $table->unsignedBigInteger('user_id');
            $table->string('sub_title')->nullable();
            $table->string('state')->default('draft');
            $table->longText('content')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->index(['tenant_id', 'state']);
            $table->index(['tenant_id', 'published_at']);
            $table->index(['tenant_id', 'title']);
            $table->index(['tenant_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};

