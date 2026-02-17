<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gallery_videos')) {
            Schema::create('gallery_videos', function (Blueprint $table) {
                $table->id();

                // Central references
                $table->string('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();

                // Tenant-private reference (no cross-DB foreign key).
                $table->unsignedBigInteger('video_id')->index();

                // Catalog reference (central, but FK may not exist yet in this repo).
                $table->unsignedBigInteger('effect_id')->nullable()->index();

                // Public metadata
                $table->string('title');
                $table->boolean('is_public')->default(true)->index();
                $table->json('tags')->nullable();

                // Denormalized asset pointers (avoid cross-DB joins at read time).
                $table->string('processed_file_url', 2048)->nullable();
                $table->string('thumbnail_url', 2048)->nullable();

                $table->timestamps();
                $table->softDeletes();

                // A tenant video can only be published once (scoped by tenant_id because video_id is only unique within a pool).
                $table->unique(['tenant_id', 'video_id']);
                $table->index(['is_public', 'created_at']);
            });

            return;
        }

        // If the table already exists (older schema), extend it with the columns needed
        // for pooled-DB tenancy + denormalized Explore reads.
        Schema::table('gallery_videos', function (Blueprint $table) {
            if (!Schema::hasColumn('gallery_videos', 'tenant_id')) {
                $table->string('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            }

            if (!Schema::hasColumn('gallery_videos', 'effect_id')) {
                $table->unsignedBigInteger('effect_id')->nullable()->after('video_id');
                $table->index('effect_id');
            }

            if (!Schema::hasColumn('gallery_videos', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('title');
                $table->index('is_public');
            }

            if (!Schema::hasColumn('gallery_videos', 'tags')) {
                $table->json('tags')->nullable()->after('is_public');
            }

            if (!Schema::hasColumn('gallery_videos', 'processed_file_url')) {
                $table->string('processed_file_url', 2048)->nullable()->after('thumbnail_url');
            }

            // Ensure URL columns can store long CDN/S3 URLs.
            if (Schema::hasColumn('gallery_videos', 'thumbnail_url')) {
                $table->string('thumbnail_url', 2048)->nullable()->change();
            }
        });

        // Add pooled uniqueness + hot-path index if missing.
        try {
            Schema::table('gallery_videos', function (Blueprint $table) {
                $table->unique(['tenant_id', 'video_id']);
            });
        } catch (\Throwable $e) {
            // ignore (already exists)
        }

        try {
            Schema::table('gallery_videos', function (Blueprint $table) {
                $table->index(['is_public', 'created_at']);
            });
        } catch (\Throwable $e) {
            // ignore (already exists)
        }
    }

    public function down(): void
    {
        // This migration may have been applied to an already-existing table.
        // We only revert the columns we add, and do not drop the whole table.
        if (!Schema::hasTable('gallery_videos')) {
            return;
        }

        try {
            Schema::table('gallery_videos', function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'video_id']);
            });
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            Schema::table('gallery_videos', function (Blueprint $table) {
                $table->dropIndex(['is_public', 'created_at']);
            });
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('gallery_videos', function (Blueprint $table) {
            foreach (['tenant_id', 'effect_id', 'is_public', 'tags', 'processed_file_url'] as $col) {
                if (Schema::hasColumn('gallery_videos', $col)) {
                    try {
                        $table->dropColumn($col);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }
        });
    }
};

