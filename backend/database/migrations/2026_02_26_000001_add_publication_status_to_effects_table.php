<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection($this->connection)->table('effects', function (Blueprint $table) {
            $table->string('publication_status', 32)
                ->default('published')
                ->index()
                ->after('is_new');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('effects', function (Blueprint $table) {
            $table->dropColumn('publication_status');
        });
    }
};
