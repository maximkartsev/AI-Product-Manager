<?php

namespace App\Services;

use App\Models\File;
use App\Models\GalleryVideo;
use App\Models\Tenant;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Tenancy;

class VideoCleanupService
{
    public function cleanupExpiredVideos(): int
    {
        $tenancy = app(Tenancy::class);
        $expiredCount = 0;

        Tenant::query()->each(function (Tenant $tenant) use (&$expiredCount, $tenancy) {
            $tenancy->initialize($tenant);

            try {
                $expiredVideos = Video::query()
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->get();

                foreach ($expiredVideos as $video) {
                    $file = $video->processed_file_id ? File::query()->find($video->processed_file_id) : null;
                    if ($file && $file->disk && $file->path) {
                        Storage::disk($file->disk)->delete($file->path);
                        $file->delete();
                    }

                    $video->status = 'expired';
                    $video->processed_file_id = null;
                    $video->is_public = false;
                    $video->save();

                    GalleryVideo::query()
                        ->where('tenant_id', (string) $video->tenant_id)
                        ->where('video_id', $video->id)
                        ->update(['is_public' => false]);

                    $expiredCount++;
                }
            } finally {
                $tenancy->end();
            }
        });

        return $expiredCount;
    }
}
