<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StudentSkillController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\OrganizationProfileController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);


Route::get('/skills', [SkillController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/student/skills', [StudentSkillController::class, 'index']);

    Route::post('/student/skills', [StudentSkillController::class, 'store']);

    Route::delete('/student/skills/{skill}', [StudentSkillController::class, 'destroy']);

    Route::get('/student/profile', [StudentProfileController::class, 'show']);
    Route::patch('/student/profile', [StudentProfileController::class, 'update']);
    Route::get('/student/onboarding', [StudentProfileController::class, 'show']);
    Route::patch('/student/onboarding', [StudentProfileController::class, 'update']);
    Route::post('/student/onboarding/complete', [StudentProfileController::class, 'completeOnboarding']);

    Route::get('/organization/onboarding', [OrganizationProfileController::class, 'show']);
    Route::patch('/organization/onboarding', [OrganizationProfileController::class, 'update']);
});
