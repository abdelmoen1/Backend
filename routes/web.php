<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

// مسارات Google OAuth على مجموعة web لأن Socialite يحفظ state في الجلسة ويتحقق منه
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
    ->middleware('throttle:oauth');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:oauth');

Route::get('/', function () {
    return view('welcome');
});
