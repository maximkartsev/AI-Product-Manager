<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BaseController;
use App\Http\Middleware\EnsureTenantMatchesUser;
use App\Http\Middleware\InitializeTenancyByDomainOrUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureWorkerToken;
use App\Http\Controllers\ComfyUiWorkerController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Controllers\AiJobController;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use \App\Http\Controllers\RecordController as RecordController;
use \App\Http\Controllers\ArticleController as ArticleController;
use \App\Http\Controllers\ActivityLogController as ActivityLogController;
use \App\Http\Controllers\RolloutController as RolloutController;
use \App\Http\Controllers\TagController as TagController;
use \App\Http\Controllers\RecordTagController as RecordTagController;
use \App\Http\Controllers\RegisterController as RegisterController;
use \App\Http\Controllers\AuthController as AuthController;
use \App\Http\Controllers\TranslationsController as TranslationsController;
use \App\Http\Controllers\PasswordController as PasswordController;
use \App\Http\Controllers\ReviewController as ReviewController;
use \App\Http\Controllers\MeController as MeController;
use \App\Http\Controllers\WalletController as WalletController;
use \App\Http\Controllers\EffectController as EffectController;
use \App\Http\Controllers\CategoryController as CategoryController;
use \App\Http\Controllers\VideoController as VideoController;
use \App\Http\Controllers\GalleryController as GalleryController;
use \App\Http\Controllers\Admin\EffectsController as AdminEffectsController;
use \App\Http\Controllers\Admin\CategoriesController as AdminCategoriesController;
use \App\Http\Controllers\Admin\UiSettingsController as AdminUiSettingsController;
use \App\Http\Controllers\Admin\UsersController as AdminUsersController;
use \App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;

/**
 * Central/public routes (no tenant initialization required).
 */
Route::post('login', [RegisterController::class,'login']);
Route::post('register', [RegisterController::class,'register']);
Route::post('password/reset', [PasswordController::class,'sendResetLink']);
Route::post('password/reset/confirm', [PasswordController::class,'reset']);

// Google OAuth
Route::get('auth/google/signin', [AuthController::class, 'redirectToGoogleSignIn']);
Route::get('auth/google/signin/callback', [AuthController::class, 'handleGoogleSignInCallback']);
Route::get('auth/google/signup', [AuthController::class, 'redirectToGoogleSignUp']);
Route::get('auth/google/signup/callback', [AuthController::class, 'handleGoogleSignUpCallback']);

// TikTok OAuth
Route::get('auth/tiktok/signin', [AuthController::class, 'redirectToTikTokSignIn']);
Route::get('auth/tiktok/signin/callback', [AuthController::class, 'handleTikTokSignInCallback']);
Route::get('auth/tiktok/signup', [AuthController::class, 'redirectToTikTokSignUp']);
Route::get('auth/tiktok/signup/callback', [AuthController::class, 'handleTikTokSignUpCallback']);

// Apple OAuth (callbacks are POST — Apple uses response_mode=form_post)
Route::get('auth/apple/signin', [AuthController::class, 'redirectToAppleSignIn']);
Route::post('auth/apple/signin/callback', [AuthController::class, 'handleAppleSignInCallback']);
Route::get('auth/apple/signup', [AuthController::class, 'redirectToAppleSignUp']);
Route::post('auth/apple/signup/callback', [AuthController::class, 'handleAppleSignUpCallback']);

// Translations
Route::get('translations/{lang}', [TranslationsController::class, 'show']);

// Public catalog endpoints (no tenant init required).
Route::get('effects', [EffectController::class,'index']);
Route::get('effects/{slugOrId}', [EffectController::class,'show']);
Route::get('categories', [CategoryController::class,'index']);
Route::get('categories/{slugOrId}', [CategoryController::class,'show']);
Route::get('gallery', [GalleryController::class,'index']);
Route::get('gallery/{id}', [GalleryController::class,'show']);

// Central-domain webhooks (tenant is resolved from the central purchase record).
Route::post('webhooks/payments', [PaymentWebhookController::class, 'handle']);

// Worker endpoints (central, token-protected).
Route::middleware([EnsureWorkerToken::class])->prefix('worker')->group(function () {
    Route::post('poll', [ComfyUiWorkerController::class, 'poll']);
    Route::post('heartbeat', [ComfyUiWorkerController::class, 'heartbeat']);
    Route::post('complete', [ComfyUiWorkerController::class, 'complete']);
    Route::post('fail', [ComfyUiWorkerController::class, 'fail']);
});

/**
 * Tenant routes (tenant resolved by domain/subdomain).
 */
Route::middleware([
    PreventAccessFromCentralDomains::class,
    'auth:sanctum',
    InitializeTenancyByDomainOrUser::class,
    EnsureTenantMatchesUser::class,
])->group(function () {
    Route::get('/me', [MeController::class,'show']);
    Route::post('/me', [MeController::class,'update']);
    Route::get('/wallet', [WalletController::class, 'show']);

    Route::middleware([EnsureAdmin::class])->group(function () {
        Route::get('/filters', [BaseController::class, 'getAvailableFilers']);
        Route::get('/filter-options', [BaseController::class, 'getFilterOptions']);
        Route::get('/columns', [BaseController::class, 'getAvailableColumns']);

        Route::prefix('admin')->group(function () {
            Route::get('/effects', [AdminEffectsController::class, 'index']);
            Route::post('/effects/uploads', [AdminEffectsController::class, 'createUpload']);
            Route::post('/effects', [AdminEffectsController::class, 'store']);
            Route::patch('/effects/{id}', [AdminEffectsController::class, 'update']);
            Route::delete('/effects/{id}', [AdminEffectsController::class, 'destroy']);

            Route::get('/categories', [AdminCategoriesController::class, 'index']);
            Route::post('/categories', [AdminCategoriesController::class, 'store']);
            Route::patch('/categories/{id}', [AdminCategoriesController::class, 'update']);
            Route::delete('/categories/{id}', [AdminCategoriesController::class, 'destroy']);

            Route::get('/ui-settings', [AdminUiSettingsController::class, 'index']);
            Route::put('/ui-settings', [AdminUiSettingsController::class, 'update']);
            Route::delete('/ui-settings', [AdminUiSettingsController::class, 'destroy']);

            Route::get('/users', [AdminUsersController::class, 'index']);
            Route::get('/users/{id}', [AdminUsersController::class, 'show']);
            Route::get('/users/{id}/purchases', [AdminUsersController::class, 'purchases']);
            Route::get('/users/{id}/tokens', [AdminUsersController::class, 'tokens']);

            Route::get('/analytics/token-spending', [AdminAnalyticsController::class, 'tokenSpending']);
        });
    });

    Route::post('/ai-jobs', [AiJobController::class, 'store']);
    Route::post('/videos/uploads', [VideoController::class, 'createUpload']);
    Route::get('/videos', [VideoController::class, 'index']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/videos/{id}', [VideoController::class, 'show']);
    Route::post('/videos/{video}/publish', [VideoController::class, 'publish']);
    Route::post('/videos/{video}/unpublish', [VideoController::class, 'unpublish']);

    Route::resource('records', RecordController::class)->except(['edit']);
    Route::resource('articles', ArticleController::class)->except(['edit']);
    Route::resource('activity-logs', ActivityLogController::class)->except(['edit']);
    Route::resource('rollouts', RolloutController::class)->except(['edit']);
    Route::resource('tags', TagController::class)->except(['edit']);
    Route::resource('record-tags', RecordTagController::class)->except(['edit']);
    Route::resource('reviews', ReviewController::class)->except(['edit']);
});
