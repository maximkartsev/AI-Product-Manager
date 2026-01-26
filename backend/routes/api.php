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
