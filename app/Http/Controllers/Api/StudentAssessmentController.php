<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assessments\AssessmentException;
use App\Services\Assessments\AssessmentSessionService;
use App\Services\Projects\OpportunityMatcher;
use App\Services\Projects\SpecializationRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقييم المهارات بأسئلة مفتوحة يولدها Gemini. الطالب يرسل نص إجابته فقط؛ لا يوجد مسار يقبل درجة أو
 * حالة نجاح من العميل، ولا يعيد أي مسار معايير التصحيح أو الإجابة المرجعية.
 */
class StudentAssessmentController extends Controller
{
    public function __construct(private readonly AssessmentSessionService $sessions) {}

    public function index(Request $request, OpportunityMatcher $matcher): JsonResponse
    {
        if ($response = $this->studentsOnly($request)) {
            return $response;
        }

        $student = $request->user()->studentProfile;

        return response()->json([
            'success' => true,
            'data' => $this->sessions->overview($student) + [
                'verified_skills' => $matcher->verifiedSkills($student)->values()->all(),
            ],
        ]);
    }

    /** قائمة المسارات المهنية (للإعداد الأولي وصفحة الملف). */
    public function specializations(Request $request): JsonResponse
    {
        if ($response = $this->studentsOnly($request)) {
            return $response;
        }

        return response()->json(['success' => true, 'data' => SpecializationRoles::publicCatalog()]);
    }

    public function start(Request $request): JsonResponse
    {
        if ($response = $this->studentsOnly($request)) {
            return $response;
        }

        $max = (int) config('assessments.max_skills_per_session', 3);
        $validated = $request->validate([
            'skill_ids' => ['required', 'array', 'min:1', "max:{$max}"],
            'skill_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'skill_ids.required' => 'اختر مهارة واحدة على الأقل للتقييم.',
            'skill_ids.min' => 'اختر مهارة واحدة على الأقل للتقييم.',
            'skill_ids.max' => "يمكن تقييم {$max} مهارات كحد أقصى في الجلسة الواحدة.",
            'skill_ids.*.integer' => 'إحدى المهارات المختارة غير صالحة.',
            'skill_ids.*.distinct' => 'اخترت المهارة نفسها أكثر من مرة.',
        ]);

        try {
            [$session, $created] = $this->sessions->start(
                $request->user()->studentProfile,
                array_map('intval', $validated['skill_ids'])
            );
        } catch (AssessmentException $exception) {
            return $this->error($exception);
        }

        return response()->json([
            'success' => true,
            'resumed' => ! $created,
            'data' => $this->sessions->present($session),
        ], $created ? 201 : 200);
    }

    public function show(Request $request, int $session): JsonResponse
    {
        if ($response = $this->studentsOnly($request)) {
            return $response;
        }

        try {
            $found = $this->sessions->find($request->user()->studentProfile, $session);
        } catch (AssessmentException $exception) {
            return $this->error($exception);
        }

        return response()->json(['success' => true, 'data' => $this->sessions->present($found)]);
    }

    public function answer(Request $request, int $session): JsonResponse
    {
        if ($response = $this->studentsOnly($request)) {
            return $response;
        }

        $maxLength = (int) config('assessments.answer_max_length', 8000);
        // answer مستثنى من قص المسافات (bootstrap/app.php) حتى تُحفظ المسافات البادئة في الكود
        $validated = $request->validate([
            'question_id' => ['required', 'string', 'max:20'],
            'answer' => ['required', 'string', "max:{$maxLength}", 'regex:/\S/u'],
        ], [
            'answer.required' => 'اكتب إجابتك قبل الإرسال.',
            'answer.string' => 'اكتب إجابتك نصًا.',
            'answer.regex' => 'اكتب إجابتك قبل الإرسال.',
            'answer.max' => "الإجابة أطول من الحد المسموح ({$maxLength} حرف).",
            'question_id.required' => 'تعذر تحديد السؤال. حدّث الصفحة.',
        ]);

        try {
            [$updated, $duplicate] = $this->sessions->answer(
                $request->user()->studentProfile,
                $session,
                $validated['question_id'],
                $validated['answer']
            );
        } catch (AssessmentException $exception) {
            return $this->error($exception);
        }

        return response()->json([
            'success' => true,
            'duplicate' => $duplicate,
            'data' => $this->sessions->present($updated),
        ]);
    }

    public function finalize(Request $request, int $session): JsonResponse
    {
        if ($response = $this->studentsOnly($request)) {
            return $response;
        }

        try {
            [$updated, $gradingError] = $this->sessions->finalize($request->user()->studentProfile, $session);
        } catch (AssessmentException $exception) {
            return $this->error($exception);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessions->present($updated),
            'grading_error' => $gradingError ? [
                'code' => $gradingError->errorCode,
                'message' => $gradingError->getMessage(),
                'retryable' => $gradingError->retryable,
            ] : null,
        ]);
    }

    private function error(AssessmentException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'retryable' => $exception->retryable,
        ] + $exception->context, $exception->status);
    }

    private function studentsOnly(Request $request): ?JsonResponse
    {
        if ($request->user()->role !== 'student' || ! $request->user()->studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الصفحة مخصصة للطلاب.',
            ], 403);
        }

        return null;
    }
}
