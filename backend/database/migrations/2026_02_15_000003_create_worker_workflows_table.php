<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('worker_workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('worker_id')->index();
            $table->unsignedBigInteger('workflow_id')->index();
            $table->timestamps();
            $table->unique(['worker_id', 'workflow_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('worker_workflows');
    }
};
