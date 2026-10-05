<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationProfileController;
use App\Http\Controllers\Api\ProjectDiscoveryController;
use App\Http\Controllers\Api\ProjectRequestController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\StudentSkillController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/skills', [SkillController::class, 'index']);

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
        Route::get('/{projectRequest}/intake', [ProjectRequestController::class, 'intake']);
        Route::post('/{projectRequest}/intake', [ProjectRequestController::class, 'saveIntake']);
        Route::post('/{projectRequest}/next-question', [ProjectDiscoveryController::class, 'nextQuestion']);
        Route::post('/{projectRequest}/answer', [ProjectDiscoveryController::class, 'answer']);
        Route::post('/{projectRequest}/analyze', [ProjectRequestController::class, 'analyze']);
        Route::post('/{projectRequest}/approve', [ProjectRequestController::class, 'approve']);
        Route::post('/{projectRequest}/revision', [ProjectRequestController::class, 'revision']);
    });
});
