<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gallery_videos')) {
            return;
        }

        Schema::table('gallery_videos', function (Blueprint $table) {
            if (Schema::hasColumn('gallery_videos', 'title')) {
                $table->dropColumn('title');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('gallery_videos')) {
            return;
        }

        Schema::table('gallery_videos', function (Blueprint $table) {
            if (!Schema::hasColumn('gallery_videos', 'title')) {
                $table->string('title')->after('effect_id');
            }
        });
    }
};
