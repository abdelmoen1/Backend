<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StudentSkillController;
use App\Http\Controllers\Api\StudentProfileController;

Route::post('/register', [AuthController::class, 'register']);


Route::get('/skills', [SkillController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/student/skills', [StudentSkillController::class, 'index']);

    Route::post('/student/skills', [StudentSkillController::class, 'store']);

    Route::delete('/student/skills/{skill}', [StudentSkillController::class, 'destroy']);

    Route::patch('/student/profile', [StudentProfileController::class, 'update']);
});
