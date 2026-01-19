<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('algorithms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('processing');
            $table->string('category')->nullable();
            $table->json('parameters')->nullable();
            $table->json('default_values')->nullable();
            $table->decimal('complexity_factor', 5, 2)->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_gpu_required')->default(false);

            $table->index('slug');
            $table->index('type');
            $table->index('category');
            $table->index('is_active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('algorithms');
    }
};
