<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LeaderProjectController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationProfileController;
use App\Http\Controllers\Api\OrganizationProjectController;
use App\Http\Controllers\Api\ProfileImageController;
use App\Http\Controllers\Api\ProjectDiscoveryController;
use App\Http\Controllers\Api\ProjectExecutionController;
use App\Http\Controllers\Api\ProjectPlanController;
use App\Http\Controllers\Api\ProjectRequestController;
use App\Http\Controllers\Api\ProjectTeamController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\StudentApplicationController;
use App\Http\Controllers\Api\StudentAssessmentController;
use App\Http\Controllers\Api\StudentOpportunityController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\StudentProjectController;
use App\Http\Controllers\Api\StudentSkillController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

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

Route::middleware(['auth:sanctum', 'active'])->group(function () {
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

    // المطابقة وتكوين الفريق: لقائد الفريق الساري الذي عيّنه مدير المنصة فقط (التحقق في المتحكم)
    Route::get('/projects/{project}/matches', [ProjectTeamController::class, 'matches'])->whereNumber('project');
    Route::patch('/projects/{project}', [ProjectTeamController::class, 'update'])->whereNumber('project');
    Route::patch('/projects/{project}/applications/{application}', [ProjectTeamController::class, 'reviewApplication'])->whereNumber(['project', 'application']);
    Route::get('/projects/{project}/team', [ProjectTeamController::class, 'showTeam'])->whereNumber('project');
    Route::post('/projects/{project}/team', [ProjectTeamController::class, 'storeTeam'])->whereNumber('project');
    Route::post('/teams/{team}/confirm', [ProjectTeamController::class, 'confirmTeam'])->whereNumber('team');

    // مساحة التخطيط والاتفاق: القائد يكتب ويقدّم، المؤسسة توافق أو تطلب تعديلًا، والطرفان يعلّقان
    Route::get('/projects/{project}/plan', [ProjectPlanController::class, 'show'])->whereNumber('project');
    Route::put('/projects/{project}/plan/draft', [ProjectPlanController::class, 'saveDraft'])->whereNumber('project');
    Route::post('/projects/{project}/plan/versions/{version}/submit', [ProjectPlanController::class, 'submit'])->whereNumber(['project', 'version']);
    Route::post('/projects/{project}/plan/versions/{version}/decision', [ProjectPlanController::class, 'decide'])->whereNumber(['project', 'version']);
    Route::post('/projects/{project}/plan/versions/{version}/comments', [ProjectPlanController::class, 'comment'])->whereNumber(['project', 'version'])->middleware('throttle:60,1');
    Route::post('/projects/{project}/start', [LeaderProjectController::class, 'start'])->whereNumber('project');
    Route::get('/leader/projects', [LeaderProjectController::class, 'index']);

    // مساحة التنفيذ: لوحة المشروع، المهام والمراحل والتسليمات، الإكمال والتقييمات (الصلاحية لكل إجراء في المتحكم)
    Route::get('/projects/{project}/execution', [ProjectExecutionController::class, 'show'])->whereNumber('project');
    Route::post('/projects/{project}/tasks', [ProjectExecutionController::class, 'storeTask'])->whereNumber('project');
    Route::patch('/projects/{project}/tasks/{task}', [ProjectExecutionController::class, 'updateTask'])->whereNumber(['project', 'task']);
    Route::patch('/projects/{project}/milestones/{milestone}', [ProjectExecutionController::class, 'updateMilestone'])->whereNumber(['project', 'milestone']);
    Route::post('/projects/{project}/tasks/{task}/deliverables', [ProjectExecutionController::class, 'submitDeliverable'])->whereNumber(['project', 'task'])->middleware('throttle:30,1');
    Route::post('/projects/{project}/deliverables/{deliverable}/review', [ProjectExecutionController::class, 'reviewDeliverable'])->whereNumber(['project', 'deliverable']);
    Route::post('/projects/{project}/complete', [ProjectExecutionController::class, 'complete'])->whereNumber('project');
    Route::post('/projects/{project}/evaluations', [ProjectExecutionController::class, 'evaluate'])->whereNumber('project');
    Route::post('/projects/{project}/feedback', [ProjectExecutionController::class, 'feedback'])->whereNumber('project');
    Route::get('/student/projects', [StudentProjectController::class, 'index']);
    Route::get('/student/evidence', [StudentProjectController::class, 'evidence']);

    Route::get('/organization/projects/{project}', [OrganizationProjectController::class, 'show'])->whereNumber('project');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // إدارة المنصة: الدور admin فقط (middleware في الخادم)
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/projects', [AdminController::class, 'projects']);
        Route::get('/projects/{project}', [AdminController::class, 'project'])->whereNumber('project');
        Route::post('/projects/{project}/review', [AdminController::class, 'review'])->whereNumber('project');
        Route::get('/projects/{project}/leader-candidates', [AdminController::class, 'leaderCandidates'])->whereNumber('project');
        Route::post('/projects/{project}/leader', [AdminController::class, 'assignLeader'])->whereNumber('project');
        Route::delete('/projects/{project}/leader', [AdminController::class, 'revokeLeader'])->whereNumber('project');
        Route::get('/users', [AdminController::class, 'users']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->whereNumber('user');
    });

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
