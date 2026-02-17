<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('worker_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('worker_id')->nullable()->index();
            $table->string('worker_identifier', 255)->nullable();
            $table->string('event', 100)->index();
            $table->unsignedBigInteger('dispatch_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('worker_audit_logs');
    }
};
