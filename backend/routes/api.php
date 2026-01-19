<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
use \App\Http\Controllers\CategoryController as CategoryController;
use \App\Http\Controllers\TierController as TierController;
use \App\Http\Controllers\PackageController as PackageController;
use \App\Http\Controllers\AiModelController as AiModelController;
use \App\Http\Controllers\AlgorithmController as AlgorithmController;
use \App\Http\Controllers\DiscountController as DiscountController;
use \App\Http\Controllers\RewardController as RewardController;
use \App\Http\Controllers\SubscriptionController as SubscriptionController;
use \App\Http\Controllers\FileController as FileController;
use \App\Http\Controllers\EffectController as EffectController;
use \App\Http\Controllers\StyleController as StyleController;
use \App\Http\Controllers\FilterController as FilterController;
use \App\Http\Controllers\OverlayController as OverlayController;
use \App\Http\Controllers\WatermarkController as WatermarkController;
use \App\Http\Controllers\PurchaseController as PurchaseController;
use \App\Http\Controllers\PaymentController as PaymentController;
use \App\Http\Controllers\CreditTransactionController as CreditTransactionController;
use \App\Http\Controllers\VideoController as VideoController;
use \App\Http\Controllers\ExportController as ExportController;
use \App\Http\Controllers\GalleryVideoController as GalleryVideoController;

Route::middleware('auth:sanctum')->get('/me', [MeController::class,'show']);
Route::middleware('auth:sanctum')->post('/me', [MeController::class,'update']);

Route::post('login', [RegisterController::class,'login']);
Route::post('register', [RegisterController::class,'register']);
Route::post('password/reset', [PasswordController::class,'sendResetLink']);
Route::post('password/reset/confirm', [PasswordController::class,'reset']);

Route::middleware(['auth:sanctum'])->resource('records', RecordController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('articles', ArticleController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('activity-logs', ActivityLogController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('rollouts', RolloutController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('tags', TagController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('record-tags', RecordTagController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('reviews', ReviewController::class)->except(['edit']);

// AI Video Effects Studio Routes
Route::middleware(['auth:sanctum'])->resource('categories', CategoryController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('tiers', TierController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('packages', PackageController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('ai-models', AiModelController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('algorithms', AlgorithmController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('discounts', DiscountController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('rewards', RewardController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('subscriptions', SubscriptionController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('files', FileController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('effects', EffectController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('styles', StyleController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('filters', FilterController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('overlays', OverlayController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('watermarks', WatermarkController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('purchases', PurchaseController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('payments', PaymentController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('credit-transactions', CreditTransactionController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('videos', VideoController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('exports', ExportController::class)->except(['edit']);
Route::middleware(['auth:sanctum'])->resource('gallery-videos', GalleryVideoController::class)->except(['edit']);
