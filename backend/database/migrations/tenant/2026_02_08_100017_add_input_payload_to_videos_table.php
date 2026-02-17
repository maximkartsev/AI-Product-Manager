<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (!Schema::hasColumn('videos', 'input_payload')) {
                $table->json('input_payload')->nullable()->after('processing_details');
            }
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (Schema::hasColumn('videos', 'input_payload')) {
                $table->dropColumn('input_payload');
            }
        });
    }
};
