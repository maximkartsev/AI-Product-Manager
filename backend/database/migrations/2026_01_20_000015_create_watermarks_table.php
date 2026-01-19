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
        Schema::create('watermarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('type')->default('image');
            $table->string('file_path')->nullable();
            $table->text('text_content')->nullable();
            $table->string('font_family')->nullable();
            $table->integer('font_size')->nullable();
            $table->string('font_color')->nullable();
            $table->string('position')->default('bottom-right');
            $table->decimal('opacity', 5, 2)->default(50);
            $table->decimal('scale', 5, 2)->default(100);
            $table->integer('margin_x')->default(10);
            $table->integer('margin_y')->default(10);
            $table->boolean('is_default')->default(false);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id');
            $table->index('type');
            $table->index('is_default');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watermarks');
    }
};
