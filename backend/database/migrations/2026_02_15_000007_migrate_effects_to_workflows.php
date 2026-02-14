<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        // No-op: Legacy per-effect workflow paths are handled by seeders.
        // WorkflowSeeder creates the canonical workflow record, and
        // EffectsSeeder links all effects to it.
    }

    public function down(): void
    {
        // Nothing to reverse.
    }
};
