<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\VideoCleanupService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('videos:cleanup-expired', function () {
    $count = app(VideoCleanupService::class)->cleanupExpiredVideos();
    $this->comment("Expired videos cleaned: {$count}");
})->purpose('Cleanup expired processed videos');
