<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollouts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('commit_id');
            $table->date('date');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'commit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollouts');
    }
};

