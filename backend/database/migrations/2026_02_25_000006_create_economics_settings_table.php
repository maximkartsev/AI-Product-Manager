<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->create('economics_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('token_usd_rate', 10, 4)->default(0.01);
            $table->decimal('spot_multiplier', 10, 4)->nullable();
            $table->json('instance_type_rates');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('economics_settings');
    }
};
