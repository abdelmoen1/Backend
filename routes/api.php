<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Api\OrganizationProfileController;
use App\Http\Controllers\Api\ProfileImageController;
use App\Http\Controllers\Api\ProjectDiscoveryController;
use App\Http\Controllers\Api\ProjectRequestController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\StudentSkillController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:oauth');

Route::get('/skills', [SkillController::class, 'index']);
Route::get(
    '/profile-images/student/{filename}',
    [ProfileImageController::class, 'student']
)
    ->where('filename', '[A-Za-z0-9][A-Za-z0-9._-]*')
    ->name('api.profile-images.student');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/student/skills', [StudentSkillController::class, 'index']);

    Route::post('/student/skills', [StudentSkillController::class, 'store']);
    Route::patch('/student/skills/{skill}', [StudentSkillController::class, 'update']);

    Route::delete('/student/skills/{skill}', [StudentSkillController::class, 'destroy']);

    Route::get('/student/profile', [StudentProfileController::class, 'show']);
    Route::patch('/student/profile', [StudentProfileController::class, 'update']);
    Route::get('/student/onboarding', [StudentProfileController::class, 'show']);
    Route::patch('/student/onboarding', [StudentProfileController::class, 'update']);
    Route::post('/student/onboarding/complete', [StudentProfileController::class, 'completeOnboarding']);

    Route::get('/organization/onboarding', [OrganizationProfileController::class, 'show']);
    Route::patch('/organization/onboarding', [OrganizationProfileController::class, 'update']);

    Route::prefix('organization/project-requests')->group(function () {
        Route::get('/', [ProjectRequestController::class, 'index']);
        Route::post('/', [ProjectRequestController::class, 'store']);
        Route::get('/{projectRequest}', [ProjectRequestController::class, 'show']);
        Route::patch('/{projectRequest}', [ProjectRequestController::class, 'update']);
        Route::delete('/{projectRequest}', [ProjectRequestController::class, 'destroy']);
        Route::post('/{projectRequest}/next-question', [ProjectDiscoveryController::class, 'nextQuestion']);
        Route::post('/{projectRequest}/answer', [ProjectDiscoveryController::class, 'answer']);
        Route::post('/{projectRequest}/analyze', [ProjectRequestController::class, 'analyze']);
        Route::post('/{projectRequest}/approve', [ProjectRequestController::class, 'approve']);
        Route::post('/{projectRequest}/revision', [ProjectRequestController::class, 'revision']);
    });
});
