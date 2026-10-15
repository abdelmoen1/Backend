<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationProfileController;
use App\Http\Controllers\Api\ProfileImageController;
use App\Http\Controllers\Api\ProjectDiscoveryController;
use App\Http\Controllers\Api\ProjectRequestController;
use App\Http\Controllers\Api\ProjectTeamController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StudentApplicationController;
use App\Http\Controllers\Api\StudentAssessmentController;
use App\Http\Controllers\Api\StudentOpportunityController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\StudentSkillController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Middleware\AssignRequestId;
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

    // تقييم المهارات بأسئلة مفتوحة يولدها Gemini؛ الطالب يرسل نص إجابته فقط والخادم يصحح ويسجل النتائج
    // X-Request-Id في كل رد يربط ما يظهر في أدوات المطور بسطر السجل المقابل
    Route::middleware(AssignRequestId::class)->group(function () {
        Route::get('/student/specializations', [StudentAssessmentController::class, 'specializations']);
        Route::get('/student/assessments', [StudentAssessmentController::class, 'index']);
        Route::get('/student/assessment-sessions/{session}', [StudentAssessmentController::class, 'show'])->whereNumber('session');
        Route::middleware('throttle:assessments')->group(function () {
            Route::post('/student/assessment-sessions', [StudentAssessmentController::class, 'start']);
            Route::post('/student/assessment-sessions/{session}/answers', [StudentAssessmentController::class, 'answer'])->whereNumber('session');
            Route::post('/student/assessment-sessions/{session}/finalize', [StudentAssessmentController::class, 'finalize'])->whereNumber('session');
        });
    });

    // فرص المشاريع المنشورة المفتوحة للتقديم مع أهلية الطالب لكل دور. /opportunities هو مسار مواصفة Sprint 4
    // و /student/opportunities المسار الأصلي؛ كلاهما للطلاب فقط وبالاستجابة نفسها
    Route::get('/student/opportunities', [StudentOpportunityController::class, 'index']);
    Route::get('/student/opportunities/{opportunity}', [StudentOpportunityController::class, 'show'])->whereNumber('opportunity');
    Route::get('/opportunities', [StudentOpportunityController::class, 'index']);
    Route::get('/opportunities/{opportunity}', [StudentOpportunityController::class, 'show'])->whereNumber('opportunity');

    // طلبات الانضمام: الطالب يقدّم ويسحب لنفسه فقط
    Route::middleware('throttle:applications')->group(function () {
        Route::post('/opportunities/{opportunity}/applications', [StudentApplicationController::class, 'store'])->whereNumber('opportunity');
        Route::post('/student/applications/{application}/withdraw', [StudentApplicationController::class, 'withdraw'])->whereNumber('application');
    });
    Route::get('/student/applications', [StudentApplicationController::class, 'index']);

    // المطابقة وتكوين الفريق: للمؤسسة صاحبة المشروع فقط (تحقق الملكية في المتحكم)
    Route::get('/projects/{project}/matches', [ProjectTeamController::class, 'matches'])->whereNumber('project');
    Route::patch('/projects/{project}', [ProjectTeamController::class, 'update'])->whereNumber('project');
    Route::patch('/projects/{project}/applications/{application}', [ProjectTeamController::class, 'reviewApplication'])->whereNumber(['project', 'application']);
    Route::get('/projects/{project}/team', [ProjectTeamController::class, 'showTeam'])->whereNumber('project');
    Route::post('/projects/{project}/team', [ProjectTeamController::class, 'storeTeam'])->whereNumber('project');
    Route::post('/teams/{team}/leader', [ProjectTeamController::class, 'assignLeader'])->whereNumber('team');
    Route::post('/teams/{team}/confirm', [ProjectTeamController::class, 'confirmTeam'])->whereNumber('team');

    Route::get('/organization/onboarding', [OrganizationProfileController::class, 'show']);
    Route::patch('/organization/onboarding', [OrganizationProfileController::class, 'update']);

    Route::prefix('organization/project-requests')->middleware(AssignRequestId::class)->group(function () {
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
