<?php

namespace App\Services\Assessments;

use App\Models\AssessmentSession;
use App\Models\AssessmentSessionAnswer;
use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Models\StudentProfile;
use App\Services\Projects\SpecializationRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * جلسات التقييم المكتوب:
 * - البدء: يتطلب مسارًا مهنيًا؛ Gemini يولد الأسئلة ومعاييرها، والجلسة تُنشأ فقط بعد التحقق منها، بنسخة
 *   مجمدة لا تتغير عند التحديث أو الاستئناف. جلسة مفتوحة واحدة لكل طالب.
 * - الإجابة: سؤال واحد في كل مرة وبالترتيب؛ تُحفظ الإجابة حرفيًا قبل أي عملية أخرى، وتكرار الإرسال لا ينشئ نسخة.
 * - التصحيح: لكل إجابة على حدة، تدريجيًا وقابلًا للاستئناف (كل إجابة تُحفظ درجتها فور تصحيحها)؛ فشل
 *   Gemini لا يغير شيئًا ولا يتطلب إعادة الاختبار.
 * - النتيجة: لكل مهارة مستقلة في skill_assessments، يحسبها Laravel من الدرجات المتحقق منها وقواعد settings.
 */
class AssessmentSessionService
{
    public const SKILL_NOT_ASSESSED = 'not_assessed';

    public function __construct(
        private readonly AssessmentQuestionGenerator $generator,
        private readonly AssessmentGrader $grader,
    ) {}

    /** نظرة عامة لصفحة التقييم: المسار، حالة كل مهارة، الجلسة المفتوحة، وسجل الجلسات. */
    public function overview(StudentProfile $student): array
    {
        $this->expireStale($student);

        $selected = $student->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
        $practical = SkillVerification::practicalSkillIds();
        $results = $student->skillAssessments()->orderByDesc('assessed_at')->orderByDesc('id')->get()->groupBy('skill_id');
        $open = $this->openSession($student);

        $skills = Skill::query()->orderBy('name')->get(['id', 'name'])->map(function (Skill $skill) use ($selected, $practical, $results): array {
            $status = $this->skillStatus($skill->id, $results->get($skill->id, collect()), $practical);

            return [
                'id' => $skill->id,
                'name' => $skill->name,
                'selected' => in_array($skill->id, $selected, true),
                'practical' => in_array($skill->id, $practical, true),
            ] + $status;
        })->all();

        return [
            'specialization' => SpecializationRoles::for($student->professional_specialization),
            'specializations' => SpecializationRoles::publicCatalog(),
            'skills' => $skills,
            'active_session' => $open ? $this->summary($open) : null,
            'history' => $student->assessmentSessions()
                ->where('status', AssessmentSession::STATUS_COMPLETED)
                ->with('results.skill:id,name')
                ->latest('completed_at')
                ->limit(10)
                ->get()
                ->map(fn (AssessmentSession $session): array => $this->summary($session))
                ->all(),
            'settings' => [
                'questions_per_skill' => (int) config('assessments.questions_per_skill'),
                'max_skills_per_session' => (int) config('assessments.max_skills_per_session'),
                'minutes_per_question' => (int) config('assessments.minutes_per_question'),
                'skill_pass_percentage' => (int) config('assessments.skill_pass_percentage'),
                'retake_after_hours' => (int) config('assessments.retake_after_hours'),
            ],
        ];
    }

    /**
     * @param  array<int, int>  $skillIds
     * @return array{0: AssessmentSession, 1: bool} الجلسة، وهل أُنشئت الآن
     */
    public function start(StudentProfile $student, array $skillIds): array
    {
        $this->expireStale($student);
        $open = $this->openSession($student);
        if ($open) {
            return [$open, false];
        }

        $specialization = SpecializationRoles::for($student->professional_specialization);
        if ($specialization['status'] !== SpecializationRoles::COMPATIBLE) {
            throw new AssessmentException('اختر مسارك المهني أولًا ليُبنى التقييم عليه.', 'specialization_required', 422);
        }

        $skills = $this->skillsForSession($student, $skillIds);
        $practical = SkillVerification::practicalSkillIds();

        // التوليد قد يستغرق ثواني؛ القفل يمنع نقرتين متتاليتين من توليد جلستين
        $lock = Cache::lock("assessment-start:{$student->id}", 180);
        if (! $lock->get()) {
            throw new AssessmentException('يجري إعداد تقييمك الآن. انتظر قليلًا ثم حدّث الصفحة.', 'session_starting', 409, retryable: true);
        }

        try {
            $assessment = $this->generator->generate(
                ['key' => $specialization['key'], 'label' => $specialization['label']],
                $skills,
                $practical
            );

            return DB::transaction(function () use ($student, $skills, $assessment, $specialization): array {
                StudentProfile::query()->whereKey($student->id)->lockForUpdate()->first();
                $open = $this->openSession($student);
                if ($open) {
                    return [$open, false];
                }

                $now = now();
                $count = count($assessment['questions']);
                $session = $student->assessmentSessions()->create([
                    'professional_specialization' => $specialization['key'],
                    'status' => AssessmentSession::STATUS_IN_PROGRESS,
                    'open_slot' => 1,
                    'title' => $assessment['title'],
                    'skill_ids' => $skills->pluck('id')->all(),
                    'questions' => $assessment['questions'],
                    'question_count' => $count,
                    'settings' => [
                        'questions_per_skill' => (int) config('assessments.questions_per_skill'),
                        'skill_pass_percentage' => (int) config('assessments.skill_pass_percentage'),
                        'question_floor_percentage' => (int) config('assessments.question_floor_percentage'),
                        'retake_after_hours' => (int) config('assessments.retake_after_hours'),
                    ],
                    'generation' => [
                        'provider' => 'gemini',
                        'model' => (string) config('services.gemini.model'),
                        'prompt_version' => AssessmentQuestionGenerator::PROMPT_VERSION,
                        'difficulty' => (string) config('assessments.difficulty'),
                    ],
                    'started_at' => $now,
                    'expires_at' => $now->copy()->addMinutes($count * (int) config('assessments.minutes_per_question', 10)),
                ]);

                // اختيار مهارة للتقييم إعلان ذاتي عنها؛ التحقق يبقى في skill_assessments وحدها
                foreach ($skills as $skill) {
                    $student->studentSkills()->firstOrCreate(['skill_id' => $skill->id]);
                }

                return [$session, true];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $open = $this->openSession($student);
            if ($open) {
                return [$open, false];
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function find(StudentProfile $student, int $sessionId): AssessmentSession
    {
        $this->expireStale($student);
        $session = $student->assessmentSessions()->whereKey($sessionId)->first();
        if (! $session) {
            throw $this->notFound();
        }

        return $session;
    }

    /**
     * يحفظ إجابة السؤال الحالي حرفيًا. إعادة إرسال الإجابة نفسها تعيد الحالة نفسها دون نسخة جديدة.
     *
     * @return array{0: AssessmentSession, 1: bool} الجلسة، وهل كانت الإجابة محفوظة مسبقًا
     */
    public function answer(StudentProfile $student, int $sessionId, string $questionId, string $answer): array
    {
        $this->expireStale($student);

        try {
            [$outcome, $session] = DB::transaction(function () use ($student, $sessionId, $questionId, $answer): array {
                $session = $student->assessmentSessions()->whereKey($sessionId)->lockForUpdate()->first();
                if (! $session) {
                    throw $this->notFound();
                }

                $existing = $session->answers()->where('question_id', $questionId)->first();
                if ($existing) {
                    if ($existing->answer !== $answer) {
                        throw new AssessmentException('أُرسلت إجابة هذا السؤال مسبقًا ولا يمكن تعديلها.', 'answer_already_submitted', 409);
                    }

                    return ['duplicate', $session];
                }

                if ($session->status !== AssessmentSession::STATUS_IN_PROGRESS) {
                    throw new AssessmentException('انتهت مرحلة الإجابة في هذا التقييم.', 'answering_closed', 409);
                }
                if ($this->isPastDeadline($session)) {
                    $this->closeAnswering($session, timedOut: true);

                    return ['expired', $session];
                }

                $position = $session->answers()->count() + 1;
                $question = $session->questions[$position - 1] ?? null;
                if (! $question || $question['id'] !== $questionId) {
                    throw new AssessmentException('هذا ليس السؤال الحالي. حدّث الصفحة لمتابعة التقييم.', 'question_out_of_order', 409, [
                        'current_question_id' => $question['id'] ?? null,
                    ]);
                }

                $session->answers()->create([
                    'question_id' => $questionId,
                    'position' => $position,
                    'skill_id' => $question['skill_id'],
                    // تُحفظ كما أرسلها الطالب دون قص أو تعديل للمسافات
                    'answer' => $answer,
                    'submitted_at' => now(),
                    'grade_status' => AssessmentSessionAnswer::GRADE_PENDING,
                    'max_points' => $question['max_points'],
                ]);
                if ($position === $session->question_count) {
                    $this->closeAnswering($session, timedOut: false);
                }

                return ['saved', $session];
            });
        } catch (UniqueConstraintViolationException) {
            // إرسال متزامن للسؤال نفسه سبق هذا الطلب
            $session = $student->assessmentSessions()->whereKey($sessionId)->firstOrFail();
            $outcome = 'duplicate';
        }

        if ($outcome === 'expired') {
            throw new AssessmentException('انتهى وقت التقييم قبل إرسال هذه الإجابة. ستُصحَّح الإجابات المحفوظة فقط.', 'session_expired', 422);
        }

        return [$session->fresh(), $outcome === 'duplicate'];
    }

    /**
     * يصحح الإجابات المعلقة ضمن مهلة زمنية ثم يسجل النتائج إذا اكتمل كل شيء. آمن للتكرار: الإجابات
     * المصححة لا تُعاد، والنتائج لا تُسجل مرتين.
     *
     * @return array{0: AssessmentSession, 1: ?AssessmentException} الجلسة، وآخر خطأ تصحيح في هذا الطلب
     */
    public function finalize(StudentProfile $student, int $sessionId): array
    {
        $session = $this->find($student, $sessionId);
        if ($session->status === AssessmentSession::STATUS_COMPLETED) {
            return [$session, null];
        }
        if ($session->status === AssessmentSession::STATUS_IN_PROGRESS) {
            throw new AssessmentException('أجب عن جميع الأسئلة أولًا.', 'answers_incomplete', 409);
        }

        $lock = Cache::lock("assessment-grade:{$session->id}", (int) config('assessments.grading_time_budget_seconds', 25) + 60);
        if (! $lock->get()) {
            // طلب آخر يصحح الآن؛ الواجهة تعيد المحاولة
            return [$session, null];
        }

        $error = null;
        try {
            $deadline = microtime(true) + max(1, (int) config('assessments.grading_time_budget_seconds', 25));
            $questions = collect($session->questions)->keyBy('id');
            $skillNames = Skill::query()->whereIn('id', $session->skill_ids)->pluck('name', 'id');

            $pending = $session->answers()->where('grade_status', AssessmentSessionAnswer::GRADE_PENDING)->get();
            foreach ($pending as $answer) {
                if (microtime(true) > $deadline) {
                    break;
                }

                $question = $questions->get($answer->question_id);
                try {
                    $grade = $this->grader->grade($question, (string) $skillNames->get($answer->skill_id), $answer->answer);
                    $answer->update([
                        'grade_status' => AssessmentSessionAnswer::GRADE_GRADED,
                        'grade' => $grade,
                        'awarded_points' => $grade['awarded_points'],
                        'graded_at' => now(),
                    ]);
                } catch (AssessmentException $exception) {
                    // فقط الرد غير الصالح المتكرر يجعل الإجابة غير قابلة للتصحيح؛ انقطاع الخدمة أو خطأ الإعدادات
                    // يبقيها معلقة حتى تعود الخدمة
                    $failures = $answer->grading_failures + ($exception->errorCode === 'ai_invalid_response' ? 1 : 0);
                    $answer->update([
                        'grading_failures' => $failures,
                        'grade_status' => $failures >= (int) config('assessments.max_grading_failures', 4)
                            ? AssessmentSessionAnswer::GRADE_UNGRADABLE
                            : AssessmentSessionAnswer::GRADE_PENDING,
                    ]);
                    $session->update(['last_grading_error' => $exception->errorCode]);
                    $error = $exception;

                    break;
                }
            }

            if (! $session->answers()->where('grade_status', AssessmentSessionAnswer::GRADE_PENDING)->exists()) {
                $this->complete($session);
            }
        } finally {
            $lock->release();
        }

        return [$session->fresh(), $error];
    }

    /** الجلسة كما يراها الطالب: السؤال الحالي فقط، دون معايير التصحيح أو الإجابة المرجعية. */
    public function present(AssessmentSession $session): array
    {
        $answers = $session->answers()->get();
        $skills = Skill::query()->whereIn('id', $session->skill_ids)->get(['id', 'name'])->keyBy('id');
        $practical = SkillVerification::practicalSkillIds();
        $current = $session->status === AssessmentSession::STATUS_IN_PROGRESS
            ? ($session->questions[$answers->count()] ?? null)
            : null;

        $view = $this->summary($session) + [
            'specialization' => SpecializationRoles::for($session->professional_specialization),
            'skills' => collect($session->skill_ids)->map(fn (int $id): array => [
                'id' => $id,
                'name' => $skills->get($id)?->name,
                'practical' => in_array($id, $practical, true),
            ])->all(),
            'answered_count' => $answers->count(),
            'started_at' => $session->started_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'ended_by_timeout' => $session->ended_by_timeout,
            'current_question' => $current ? [
                'id' => $current['id'],
                'position' => $current['position'],
                'skill' => ['id' => $current['skill_id'], 'name' => $skills->get($current['skill_id'])?->name],
                'format' => $current['format'],
                'language' => $current['language'],
                'difficulty' => $current['difficulty'],
                'prompt' => $current['prompt'],
                'code_context' => $current['code_context'],
            ] : null,
            'grading' => null,
            'results' => null,
        ];

        if ($session->status === AssessmentSession::STATUS_GRADING) {
            $view['grading'] = [
                'graded' => $answers->where('grade_status', '!=', AssessmentSessionAnswer::GRADE_PENDING)->count(),
                'total' => $answers->count(),
                'last_error' => $session->last_grading_error,
            ];
        }

        if ($session->status === AssessmentSession::STATUS_COMPLETED) {
            $view['results'] = $this->presentResults($session, $answers, $skills, $practical);
        }

        return $view;
    }

    private function summary(AssessmentSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status,
            'question_count' => $session->question_count,
            'skill_names' => Skill::query()->whereIn('id', $session->skill_ids)->orderBy('name')->pluck('name')->all(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'outcomes' => $session->status === AssessmentSession::STATUS_COMPLETED
                ? $session->results()->with('skill:id,name')->get()->map(fn (SkillAssessment $result): array => [
                    'skill' => $result->skill?->name,
                    'status' => $result->status,
                    'score' => $result->score,
                ])->all()
                : [],
        ];
    }

    /**
     * النتيجة لكل مهارة مع ملاحظات كل سؤال بعد اكتمال التصحيح فقط. لا تُعرض المعايير ولا الإجابة المرجعية.
     */
    private function presentResults(AssessmentSession $session, Collection $answers, Collection $skills, array $practical): array
    {
        $selected = $session->studentProfile->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
        $results = $session->results()->get()->keyBy('skill_id');
        $answersById = $answers->keyBy('question_id');

        return collect($session->skill_ids)->map(function (int $skillId) use ($session, $results, $answersById, $skills, $practical, $selected): array {
            $result = $results->get($skillId);
            $isPractical = in_array($skillId, $practical, true);

            return [
                'skill' => ['id' => $skillId, 'name' => $skills->get($skillId)?->name],
                'status' => $result?->status,
                'score' => $result?->score,
                'reason' => $result?->notes,
                'counts_for_matching' => $result !== null
                    && in_array($skillId, $selected, true)
                    && SkillVerification::counts($result, $practical),
                'needs_human_verification' => $isPractical,
                'next_attempt_at' => $result?->status === SkillAssessment::STATUS_FAILED
                    ? $result->assessed_at->copy()->addHours((int) $session->settings['retake_after_hours'])->toIso8601String()
                    : null,
                'questions' => collect($session->questions)
                    ->where('skill_id', $skillId)
                    ->map(function (array $question) use ($answersById): array {
                        $answer = $answersById->get($question['id']);

                        return [
                            'position' => $question['position'],
                            'prompt' => $question['prompt'],
                            'answered' => $answer !== null,
                            'grade_status' => $answer?->grade_status,
                            'awarded_points' => $answer?->awarded_points,
                            'max_points' => $question['max_points'],
                            'feedback' => $answer?->grade['feedback'] ?? null,
                            'missing_concepts' => $answer?->grade['missing_concepts'] ?? [],
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        })->all();
    }

    /**
     * يحسب نتيجة كل مهارة من الدرجات المحفوظة ويسجلها مرة واحدة:
     * - إجابة غير قابلة للتصحيح → incomplete (لا نجاح ولا مهلة إعادة).
     * - سؤال دون إجابة (انتهى الوقت) أو عدد أسئلة أقل من المطلوب → failed.
     * - وإلا ناجح إذا بلغ مجموع النقاط skill_pass_percentage ولم تقل أي إجابة عن question_floor_percentage.
     */
    private function complete(AssessmentSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session = AssessmentSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if ($session->status === AssessmentSession::STATUS_COMPLETED) {
                return;
            }

            $settings = $session->settings;
            $answers = $session->answers()->get()->keyBy('question_id');
            foreach ($session->skill_ids as $skillId) {
                $questions = collect($session->questions)->where('skill_id', $skillId);
                [$status, $score, $reason] = $this->skillOutcome($questions, $answers, $settings);

                SkillAssessment::query()->firstOrCreate(
                    ['assessment_session_id' => $session->id, 'skill_id' => $skillId],
                    [
                        'student_profile_id' => $session->student_profile_id,
                        'status' => $status,
                        'score' => $score,
                        'source' => SkillAssessment::SOURCE_AI_WRITTEN,
                        'notes' => $reason,
                        'assessed_at' => now(),
                    ]
                );
            }

            $session->update([
                'status' => AssessmentSession::STATUS_COMPLETED,
                'open_slot' => null,
                'completed_at' => now(),
                'last_grading_error' => null,
            ]);
        });
    }

    /** @return array{0: string, 1: ?int, 2: string} */
    public function skillOutcome(Collection $questions, Collection $answers, array $settings): array
    {
        $awarded = 0;
        $max = 0;
        $belowFloor = false;

        foreach ($questions as $question) {
            $answer = $answers->get($question['id']);
            if ($answer?->grade_status === AssessmentSessionAnswer::GRADE_UNGRADABLE) {
                return [SkillAssessment::STATUS_INCOMPLETE, null, 'grading_unavailable'];
            }
            $points = $answer?->grade_status === AssessmentSessionAnswer::GRADE_GRADED ? (int) $answer->awarded_points : 0;
            $awarded += $points;
            $max += (int) $question['max_points'];
            if ($points * 100 < (int) $settings['question_floor_percentage'] * (int) $question['max_points']) {
                $belowFloor = true;
            }
        }

        $score = $max > 0 ? intdiv($awarded * 100, $max) : 0;
        $unanswered = $questions->contains(fn (array $question): bool => $answers->get($question['id']) === null);
        if ($questions->count() < (int) $settings['questions_per_skill'] || $unanswered) {
            return [SkillAssessment::STATUS_FAILED, $score, 'unanswered_questions'];
        }

        // مقارنة بالأعداد الصحيحة حتى لا يرفع التقريب نتيجة راسبة
        $passed = $awarded * 100 >= (int) $settings['skill_pass_percentage'] * $max && ! $belowFloor;

        return [
            $passed ? SkillAssessment::STATUS_PASSED : SkillAssessment::STATUS_FAILED,
            $score,
            $passed ? 'passed' : ($belowFloor ? 'weak_answer' : 'below_threshold'),
        ];
    }

    /**
     * @param  array<int, int>  $skillIds
     * @return Collection<int, Skill>
     */
    private function skillsForSession(StudentProfile $student, array $skillIds): Collection
    {
        $skills = Skill::query()->whereIn('id', $skillIds)->get()->keyBy('id');
        $ordered = collect($skillIds)->map(fn (int $id): ?Skill => $skills->get($id))->filter()->values();
        if ($ordered->count() !== count($skillIds)) {
            throw new AssessmentException('إحدى المهارات المختارة غير موجودة في قائمة المنصة.', 'unknown_skill', 422);
        }

        $results = $student->skillAssessments()->whereIn('skill_id', $skillIds)->orderByDesc('assessed_at')->orderByDesc('id')->get()->groupBy('skill_id');
        $practical = SkillVerification::practicalSkillIds();
        $passed = [];
        $waiting = [];
        foreach ($ordered as $skill) {
            $status = $this->skillStatus($skill->id, $results->get($skill->id, collect()), $practical);
            if ($status['status'] === SkillAssessment::STATUS_PASSED) {
                $passed[] = $skill->name;
            } elseif ($status['next_attempt_at'] !== null) {
                $waiting[] = ['skill' => $skill->name, 'next_attempt_at' => $status['next_attempt_at']];
            }
        }

        if ($passed !== []) {
            throw new AssessmentException('اجتزت تقييم هذه المهارات بالفعل: '.implode('، ', $passed).'. أزلها من الاختيار.', 'skill_already_passed', 422, ['skills' => $passed]);
        }
        if ($waiting !== []) {
            throw new AssessmentException('لا يمكن إعادة تقييم بعض المهارات قبل انتهاء مدة الانتظار.', 'retake_not_available', 409, ['skills' => $waiting]);
        }

        return $ordered;
    }

    /**
     * حالة المهارة للطالب: ناجح إذا وُجدت نتيجة ناجحة (لا تُلغى بنتيجة لاحقة)، وإلا آخر نتيجة.
     *
     * @return array{status: string, verified: bool, score: ?int, source: ?string, assessed_at: ?string, next_attempt_at: ?string, can_assess: bool}
     */
    private function skillStatus(int $skillId, Collection $results, array $practical): array
    {
        $passed = $results->firstWhere('status', SkillAssessment::STATUS_PASSED);
        $latest = $passed ?? $results->first();
        $next = null;
        if (! $passed && $latest?->status === SkillAssessment::STATUS_FAILED) {
            $candidate = $latest->assessed_at->copy()->addHours((int) config('assessments.retake_after_hours', 24));
            $next = $candidate->isFuture() ? $candidate->toIso8601String() : null;
        }

        return [
            'status' => $latest?->status ?? self::SKILL_NOT_ASSESSED,
            'verified' => $results->contains(fn (SkillAssessment $result): bool => SkillVerification::counts($result, $practical)),
            'score' => $latest?->score,
            'source' => $latest?->source,
            'assessed_at' => $latest?->assessed_at?->toIso8601String(),
            'next_attempt_at' => $next,
            'can_assess' => ! $passed && $next === null,
        ];
    }

    private function openSession(StudentProfile $student): ?AssessmentSession
    {
        return $student->assessmentSessions()->whereNotNull('open_slot')->first();
    }

    private function isPastDeadline(AssessmentSession $session): bool
    {
        return now()->greaterThan($session->expires_at->copy()->addSeconds((int) config('assessments.submit_grace_seconds', 60)));
    }

    private function closeAnswering(AssessmentSession $session, bool $timedOut): void
    {
        $session->update([
            'status' => AssessmentSession::STATUS_GRADING,
            'answering_ended_at' => $timedOut ? $session->expires_at : now(),
            'ended_by_timeout' => $timedOut,
        ]);
    }

    /** الجلسة التي انتهى وقتها تنتقل إلى التصحيح بما حُفظ من إجابات؛ الأسئلة دون إجابة لا تُحتسب. */
    private function expireStale(StudentProfile $student): void
    {
        $student->assessmentSessions()
            ->where('status', AssessmentSession::STATUS_IN_PROGRESS)
            ->where('expires_at', '<', now()->subSeconds((int) config('assessments.submit_grace_seconds', 60)))
            ->get()
            ->each(fn (AssessmentSession $session) => $this->closeAnswering($session, timedOut: true));
    }

    private function notFound(): AssessmentException
    {
        return new AssessmentException('لم يتم العثور على جلسة التقييم.', 'session_not_found', 404);
    }
}
