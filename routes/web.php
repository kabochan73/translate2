<?php

use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

// ダッシュボードは作らない。ルートは動画一覧へ。
Route::redirect('/', '/videos');

Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
Route::post('/videos', [VideoController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('videos.store');
Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');
Route::get('/videos/{video}/status', [VideoController::class, 'status'])
    ->middleware('throttle:60,1')
    ->name('videos.status');
Route::post('/videos/{video}/retry', [VideoController::class, 'retry'])
    ->middleware('throttle:20,1')
    ->name('videos.retry');
