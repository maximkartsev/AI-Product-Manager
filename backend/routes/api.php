<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureTenantMatchesUser;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use \App\Http\Controllers\RecordController as RecordController;
use \App\Http\Controllers\ArticleController as ArticleController;
use \App\Http\Controllers\ActivityLogController as ActivityLogController;
use \App\Http\Controllers\RolloutController as RolloutController;
use \App\Http\Controllers\TagController as TagController;
use \App\Http\Controllers\RecordTagController as RecordTagController;
use \App\Http\Controllers\RegisterController as RegisterController;
use \App\Http\Controllers\PasswordController as PasswordController;
use \App\Http\Controllers\ReviewController as ReviewController;
use \App\Http\Controllers\MeController as MeController;
use \App\Http\Controllers\EffectController as EffectController;

/**
 * Central/public routes (no tenant initialization required).
 */
Route::post('login', [RegisterController::class,'login']);
Route::post('register', [RegisterController::class,'register']);
Route::post('password/reset', [PasswordController::class,'sendResetLink']);
Route::post('password/reset/confirm', [PasswordController::class,'reset']);

// Public catalog endpoints (no tenant init required).
Route::get('effects', [EffectController::class,'index']);
Route::get('effects/{slugOrId}', [EffectController::class,'show']);

// Central-domain webhooks (tenant is resolved from the central purchase record).
Route::post('webhooks/payments', [PaymentWebhookController::class, 'handle']);

/**
 * Tenant routes (tenant resolved by domain/subdomain).
 */
Route::middleware([
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    'auth:sanctum',
    EnsureTenantMatchesUser::class,
])->group(function () {
    Route::get('/me', [MeController::class,'show']);
    Route::post('/me', [MeController::class,'update']);

    Route::resource('records', RecordController::class)->except(['edit']);
    Route::resource('articles', ArticleController::class)->except(['edit']);
    Route::resource('activity-logs', ActivityLogController::class)->except(['edit']);
    Route::resource('rollouts', RolloutController::class)->except(['edit']);
    Route::resource('tags', TagController::class)->except(['edit']);
    Route::resource('record-tags', RecordTagController::class)->except(['edit']);
    Route::resource('reviews', ReviewController::class)->except(['edit']);
});
