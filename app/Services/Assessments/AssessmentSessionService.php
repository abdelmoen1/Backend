<?php

namespace App\Services\Assessments;

use App\Models\AssessmentSession;
use App\Models\AssessmentSessionAnswer;
use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Models\StudentProfile;
use App\Services\Projects\SpecializationRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * محاولات التقييم المكتوب، كل محاولة لمهارة واحدة بمستوى يختاره الطالب:
 * - البدء: يتطلب مسارًا مهنيًا ومستوى؛ Gemini يولد 10 أسئلة جديدة (7 عملية + 3 كتابية) متجنبًا أسئلة الطالب
 *   السابقة للمهارة والمستوى نفسيهما، والجلسة تُنشأ فقط بعد التحقق منها بنسخة مجمدة. محاولة مفتوحة واحدة لكل
 *   طالب: التحديث أو طلب البدء مجددًا يعيدها بالأسئلة نفسها.
 * - إعادة المحاولة: مسموحة دائمًا، حتى بعد النجاح (مثل الانتقال لمستوى أعلى)، مع مهلة للمهارة والمستوى نفسيهما
 *   وحد يومي لعدد المحاولات. النتائج السابقة تبقى، ورسوب لاحق لا يلغي نجاحًا سابقًا.
 * - الإجابة: سؤال واحد في كل مرة وبالترتيب؛ تُحفظ حرفيًا، وتكرار الإرسال لا ينشئ نسخة.
 * - التصحيح: لكل إجابة على حدة، تدريجيًا وقابلًا للاستئناف؛ فشل Gemini لا يغير شيئًا.
 * - النتيجة: في skill_assessments بمستوى المحاولة، يحسبها Laravel من الدرجات المتحقق منها وقواعد settings.
 */
class AssessmentSessionService
{
    public const SKILL_NOT_ASSESSED = 'not_assessed';

    public function __construct(
        private readonly AssessmentQuestionGenerator $generator,
        private readonly AssessmentGrader $grader,
    ) {}

    /** نظرة عامة لصفحة التقييم: المسار، حالة كل مهارة ومستوياتها، المحاولة المفتوحة، والسجل. */
    public function overview(StudentProfile $student): array
    {
        $this->expireStale($student);

        $selected = $student->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
        $practical = SkillVerification::practicalSkillIds();
        $nonProgramming = SkillVerification::nonProgrammingSkillIds();
        $results = $this->resultsBySkill($student);
        $open = $this->openSession($student);

        $skills = Skill::query()->orderBy('name')->get(['id', 'name'])->map(function (Skill $skill) use ($selected, $practical, $nonProgramming, $results): array {
            return [
                'id' => $skill->id,
                'name' => $skill->name,
                'selected' => in_array($skill->id, $selected, true),
                'practical' => in_array($skill->id, $practical, true),
                'programming' => ! in_array($skill->id, $nonProgramming, true),
            ] + $this->skillStatus($results->get($skill->id, collect()), $practical);
        })->all();

        return [
            'specialization' => SpecializationRoles::for($student->professional_specialization),
            'specializations' => SpecializationRoles::publicCatalog(),
            'skills' => $skills,
            'active_session' => $open ? $this->summary($open) : null,
            'history' => $student->assessmentSessions()
                ->where('status', AssessmentSession::STATUS_COMPLETED)
                ->latest('completed_at')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (AssessmentSession $session): array => $this->summary($session))
                ->all(),
            'attempts' => $this->attemptAllowance($student),
            'settings' => [
                'levels' => SkillLevels::catalog(),
                'questions' => self::questionMix() + ['total' => array_sum(self::questionMix())],
                'time_limit_minutes' => array_combine(SkillLevels::ALL, array_map(self::timeLimitMinutes(...), SkillLevels::ALL)),
                'skill_pass_percentage' => (int) config('assessments.skill_pass_percentage'),
                'practical_pass_percentage' => (int) config('assessments.practical_pass_percentage'),
                'retake_after_hours' => (int) config('assessments.retake_after_hours'),
                'max_attempts_per_day' => (int) config('assessments.max_attempts_per_day'),
            ],
        ];
    }

    /**
     * يبدأ محاولة جديدة لمهارة واحدة بالمستوى المطلوب، أو يعيد المحاولة المفتوحة كما هي.
     *
     * @return array{0: AssessmentSession, 1: bool} الجلسة، وهل أُنشئت الآن
     */
    public function start(StudentProfile $student, int $skillId, string $level): array
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
        if (! SkillLevels::valid($level)) {
            throw new AssessmentException('اختر مستوى التقييم: مبتدئ أو متوسط أو متقدم.', 'level_required', 422);
        }

        $skill = Skill::query()->find($skillId);
        if (! $skill) {
            throw new AssessmentException('المهارة المختارة غير موجودة في قائمة المنصة.', 'unknown_skill', 422);
        }

        $practical = SkillVerification::practicalSkillIds();
        $status = $this->skillStatus($this->resultsBySkill($student, $skill->id)->get($skill->id, collect()), $practical);
        $next = $status['levels'][$level]['next_attempt_at'];
        if ($next !== null) {
            throw new AssessmentException(
                'لا يمكن إعادة تقييم '.$skill->name.' بمستوى '.SkillLevels::label($level).' قبل انتهاء مدة الانتظار. يمكنك اختيار مستوى آخر أو مهارة أخرى.',
                'retake_not_available',
                409,
                ['skill' => $skill->name, 'level' => $level, 'next_attempt_at' => $next]
            );
        }

        $allowance = $this->attemptAllowance($student);
        if ($allowance['remaining'] === 0) {
            throw new AssessmentException(
                'بلغت الحد الأقصى لعدد محاولات التقييم خلال 24 ساعة. يمكنك بدء محاولة جديدة لاحقًا.',
                'attempt_limit_reached',
                429,
                ['next_attempt_at' => $allowance['next_attempt_at']]
            );
        }

        // التوليد قد يستغرق ثواني؛ القفل يمنع نقرتين متتاليتين من توليد محاولتين
        $lock = Cache::lock("assessment-start:{$student->id}", 180);
        if (! $lock->get()) {
            throw new AssessmentException('يجري إعداد تقييمك الآن. انتظر قليلًا ثم حدّث الصفحة.', 'session_starting', 409, retryable: true);
        }

        try {
            $mix = self::questionMix();
            $minutes = self::timeLimitMinutes($level);
            $programming = ! in_array($skill->id, SkillVerification::nonProgrammingSkillIds(), true);
            $previous = $this->previousPrompts($student, $skill->id, $level);
            $assessment = $this->generator->generate(
                ['key' => $specialization['key'], 'label' => $specialization['label']],
                $skill,
                $level,
                $programming,
                in_array($skill->id, $practical, true),
                $mix,
                $minutes,
                $previous,
            );

            return DB::transaction(function () use ($student, $skill, $level, $assessment, $specialization, $mix, $minutes, $programming, $previous): array {
                StudentProfile::query()->whereKey($student->id)->lockForUpdate()->first();
                $open = $this->openSession($student);
                if ($open) {
                    return [$open, false];
                }

                $now = now();
                $session = $student->assessmentSessions()->create([
                    'skill_id' => $skill->id,
                    'requested_level' => $level,
                    'professional_specialization' => $specialization['key'],
                    'status' => AssessmentSession::STATUS_IN_PROGRESS,
                    'open_slot' => 1,
                    'title' => $assessment['title'],
                    'skill_ids' => [$skill->id],
                    'questions' => $assessment['questions'],
                    'question_count' => count($assessment['questions']),
                    'settings' => [
                        'version' => 2,
                        'level' => $level,
                        'questions' => $mix,
                        'time_limit_minutes' => $minutes,
                        'skill_pass_percentage' => (int) config('assessments.skill_pass_percentage'),
                        'practical_pass_percentage' => (int) config('assessments.practical_pass_percentage'),
                        'question_floor_percentage' => (int) config('assessments.question_floor_percentage'),
                        'max_weak_answers' => (int) config('assessments.max_weak_answers'),
                        'retake_after_hours' => (int) config('assessments.retake_after_hours'),
                    ],
                    'generation' => [
                        'provider' => 'gemini',
                        'model' => (string) config('services.gemini.model'),
                        'prompt_version' => AssessmentQuestionGenerator::PROMPT_VERSION,
                        'difficulty' => $level,
                        'programming' => $programming,
                        'avoided_previous_questions' => count($previous),
                    ],
                    'started_at' => $now,
                    'expires_at' => $now->copy()->addMinutes($minutes),
                ]);

                // اختيار مهارة للتقييم إعلان ذاتي عنها؛ التحقق يبقى في skill_assessments وحدها
                $student->studentSkills()->firstOrCreate(['skill_id' => $skill->id]);

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
     * يصحح الإجابات المعلقة ضمن مهلة زمنية ثم يسجل النتيجة إذا اكتمل كل شيء. آمن للتكرار: الإجابات
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
            // التركيبة الفعلية للأسئلة المحفوظة (لا ما ادعاه النموذج)
            'question_mix' => [
                'practical' => collect($session->questions)->where('category', AssessmentQuestionGenerator::CATEGORY_PRACTICAL)->count(),
                'written' => collect($session->questions)->where('category', AssessmentQuestionGenerator::CATEGORY_WRITTEN)->count(),
            ],
            'answered_count' => $answers->count(),
            'started_at' => $session->started_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'ended_by_timeout' => $session->ended_by_timeout,
            'current_question' => $current ? [
                'id' => $current['id'],
                'position' => $current['position'],
                'skill' => ['id' => $current['skill_id'], 'name' => $skills->get($current['skill_id'])?->name],
                'type' => $current['type'] ?? null,
                'category' => $current['category'] ?? null,
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
        $names = Skill::query()->whereIn('id', $session->skill_ids)->orderBy('name')->pluck('name', 'id');

        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status,
            'question_count' => $session->question_count,
            'skill' => $session->skill_id ? ['id' => $session->skill_id, 'name' => $names->get($session->skill_id)] : null,
            'requested_level' => $session->requested_level,
            'requested_level_label' => SkillLevels::label($session->requested_level),
            'skill_names' => $names->values()->all(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'outcomes' => $session->status === AssessmentSession::STATUS_COMPLETED
                ? $session->results()->with('skill:id,name')->get()->map(fn (SkillAssessment $result): array => [
                    'skill' => $result->skill?->name,
                    'status' => $result->status,
                    'score' => $result->score,
                    'level' => SkillVerification::levelOf($result),
                    'level_label' => SkillLevels::label(SkillVerification::levelOf($result)),
                ])->all()
                : [],
        ];
    }

    /**
     * النتيجة لكل مهارة مع ملاحظات كل سؤال بعد اكتمال التصحيح فقط. لا تُعرض المعايير ولا الإجابة المرجعية.
     */
    private function presentResults(AssessmentSession $session, Collection $answers, Collection $skills, array $practical): array
    {
        $student = $session->studentProfile;
        $selected = $student->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
        $allResults = $this->resultsBySkill($student);
        $results = $session->results()->get()->keyBy('skill_id');
        $answersById = $answers->keyBy('question_id');

        return collect($session->skill_ids)->map(function (int $skillId) use ($session, $results, $allResults, $answersById, $skills, $practical, $selected): array {
            $result = $results->get($skillId);
            $isPractical = in_array($skillId, $practical, true);
            $verified = SkillVerification::highestLevel($allResults->get($skillId, collect()), $practical);
            $level = $result ? SkillVerification::levelOf($result) : $session->requested_level;
            $graded = in_array($result?->status, [SkillAssessment::STATUS_PASSED, SkillAssessment::STATUS_FAILED], true);

            return [
                'skill' => ['id' => $skillId, 'name' => $skills->get($skillId)?->name],
                'status' => $result?->status,
                'score' => $result?->score,
                'reason' => $result?->notes,
                'level' => $level,
                'level_label' => SkillLevels::label($level),
                // أعلى مستوى مُثبت الآن لهذه المهارة (لا يخفضه رسوب هذه المحاولة)
                'verified_level' => $verified,
                'verified_level_label' => SkillLevels::label($verified),
                'counts_for_matching' => $result !== null
                    && in_array($skillId, $selected, true)
                    && SkillVerification::counts($result, $practical),
                'needs_human_verification' => $isPractical,
                'next_attempt_at' => $graded
                    ? $result->assessed_at->copy()->addHours((int) $session->settings['retake_after_hours'])->toIso8601String()
                    : null,
                'questions' => collect($session->questions)
                    ->where('skill_id', $skillId)
                    ->map(function (array $question) use ($answersById): array {
                        $answer = $answersById->get($question['id']);

                        return [
                            'position' => $question['position'],
                            'category' => $question['category'] ?? null,
                            'type' => $question['type'] ?? null,
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
     * يحسب نتيجة المهارة من الدرجات المحفوظة ويسجلها مرة واحدة بمستوى المحاولة. نتيجة جديدة لا تعدّل
     * أو تحذف أي نتيجة سابقة، فالمستوى المُثبت سابقًا يبقى كما هو.
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
                        // جلسات الإصدار السابق بلا مستوى مطلوب تُعد "مبتدئ" كبقية النتائج السابقة
                        'level' => $session->requested_level ?? SkillLevels::BEGINNER,
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

    /**
     * نتيجة مهارة من أسئلتها فقط (لا تؤثر مهارة في أخرى)، بقواعد settings المحفوظة في الجلسة:
     * - إجابة غير قابلة للتصحيح → incomplete (لا نجاح ولا مهلة إعادة).
     * - عدد أسئلة أقل من المطلوب → failed.
     * - ناجح إذا بلغ المجموع skill_pass_percentage، وبلغت الأسئلة العملية practical_pass_percentage، ولم تزد
     *   الإجابات الضعيفة (دون question_floor_percentage أو دون إجابة) عن max_weak_answers.
     * جلسات الإصدار السابق ليس فيها practical_pass_percentage ولا max_weak_answers (= 0)، فتبقى قواعدها كما كانت.
     *
     * @return array{0: string, 1: ?int, 2: string}
     */
    public function skillOutcome(Collection $questions, Collection $answers, array $settings): array
    {
        $awarded = 0;
        $max = 0;
        $practicalAwarded = 0;
        $practicalMax = 0;
        $weak = 0;
        $unanswered = false;

        foreach ($questions as $question) {
            $answer = $answers->get($question['id']);
            if ($answer?->grade_status === AssessmentSessionAnswer::GRADE_UNGRADABLE) {
                return [SkillAssessment::STATUS_INCOMPLETE, null, 'grading_unavailable'];
            }
            $unanswered = $unanswered || $answer === null;
            $points = $answer?->grade_status === AssessmentSessionAnswer::GRADE_GRADED ? (int) $answer->awarded_points : 0;
            $awarded += $points;
            $max += (int) $question['max_points'];
            if (($question['category'] ?? null) === AssessmentQuestionGenerator::CATEGORY_PRACTICAL) {
                $practicalAwarded += $points;
                $practicalMax += (int) $question['max_points'];
            }
            if ($points * 100 < (int) $settings['question_floor_percentage'] * (int) $question['max_points']) {
                $weak++;
            }
        }

        $score = $max > 0 ? intdiv($awarded * 100, $max) : 0;
        $required = isset($settings['questions']) ? array_sum($settings['questions']) : (int) ($settings['questions_per_skill'] ?? 1);
        if ($questions->count() < $required) {
            return [SkillAssessment::STATUS_FAILED, $score, 'unanswered_questions'];
        }

        // مقارنات بالأعداد الصحيحة حتى لا يرفع التقريب نتيجة راسبة
        $totalOk = $awarded * 100 >= (int) $settings['skill_pass_percentage'] * $max;
        $practicalOk = ! isset($settings['practical_pass_percentage']) || $practicalMax === 0
            || $practicalAwarded * 100 >= (int) $settings['practical_pass_percentage'] * $practicalMax;
        $weakOk = $weak <= (int) ($settings['max_weak_answers'] ?? 0);

        if ($totalOk && $practicalOk && $weakOk) {
            return [SkillAssessment::STATUS_PASSED, $score, 'passed'];
        }

        return [SkillAssessment::STATUS_FAILED, $score, match (true) {
            $unanswered && ! $weakOk => 'unanswered_questions',
            ! $totalOk => 'below_threshold',
            ! $practicalOk => 'practical_below_threshold',
            default => 'weak_answer',
        }];
    }

    /** @return array{practical: int, written: int} */
    public static function questionMix(): array
    {
        return [
            'practical' => max(1, (int) config('assessments.questions.practical', 7)),
            'written' => max(0, (int) config('assessments.questions.written', 3)),
        ];
    }

    public static function timeLimitMinutes(string $level): int
    {
        return max(5, (int) config("assessments.time_limit_minutes.{$level}", 45));
    }

    /** @return Collection<int, Collection<int, SkillAssessment>> نتائج الطالب لكل مهارة، الأحدث أولًا */
    private function resultsBySkill(StudentProfile $student, ?int $skillId = null): Collection
    {
        return $student->skillAssessments()
            ->when($skillId !== null, fn ($query) => $query->where('skill_id', $skillId))
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('skill_id');
    }

    /**
     * حالة المهارة للطالب: أعلى مستوى مُثبت (لا يخفضه رسوب لاحق)، وحالة كل مستوى ومهلة إعادته.
     *
     * @param  Collection<int, SkillAssessment>  $results  الأحدث أولًا
     */
    private function skillStatus(Collection $results, array $practical): array
    {
        $verified = SkillVerification::highestLevel($results, $practical);
        $passed = $results->firstWhere('status', SkillAssessment::STATUS_PASSED);
        $latest = $results->first();

        $levels = [];
        foreach (SkillLevels::ALL as $level) {
            $atLevel = $results->filter(fn (SkillAssessment $result): bool => SkillVerification::levelOf($result) === $level)->values();
            $levelPassed = $atLevel->firstWhere('status', SkillAssessment::STATUS_PASSED);
            $levelLatest = $atLevel->first();
            $next = $this->nextAttemptAt($atLevel);
            $levels[$level] = [
                'level' => $level,
                'label' => SkillLevels::LABELS[$level],
                'status' => $levelPassed?->status ?? $levelLatest?->status ?? self::SKILL_NOT_ASSESSED,
                'score' => ($levelPassed ?? $levelLatest)?->score,
                'verified' => $atLevel->contains(fn (SkillAssessment $result): bool => SkillVerification::counts($result, $practical)),
                'attempts' => $atLevel->count(),
                'next_attempt_at' => $next,
                'can_assess' => $next === null,
            ];
        }

        return [
            'status' => $passed?->status ?? $latest?->status ?? self::SKILL_NOT_ASSESSED,
            'verified' => $verified !== null,
            'verified_level' => $verified,
            'verified_level_label' => SkillLevels::label($verified),
            'score' => ($passed ?? $latest)?->score,
            'source' => ($passed ?? $latest)?->source,
            'assessed_at' => ($passed ?? $latest)?->assessed_at?->toIso8601String(),
            'levels' => $levels,
            'can_assess' => collect($levels)->contains('can_assess', true),
        ];
    }

    /** مهلة إعادة المهارة بالمستوى نفسه بعد آخر محاولة مكتملة التصحيح (ناجحة أو راسبة). */
    private function nextAttemptAt(Collection $resultsAtLevel): ?string
    {
        $last = $resultsAtLevel->first(fn (SkillAssessment $result): bool => $result->source === SkillAssessment::SOURCE_AI_WRITTEN
            && in_array($result->status, [SkillAssessment::STATUS_PASSED, SkillAssessment::STATUS_FAILED], true));
        if (! $last) {
            return null;
        }

        $next = $last->assessed_at->copy()->addHours((int) config('assessments.retake_after_hours', 24));

        return $next->isFuture() ? $next->toIso8601String() : null;
    }

    /**
     * عدد المحاولات الجديدة المتبقية خلال آخر 24 ساعة (كل محاولة تكلف توليدًا وعشرة تصحيحات).
     *
     * @return array{max: int, remaining: ?int, next_attempt_at: ?string}
     */
    private function attemptAllowance(StudentProfile $student): array
    {
        $max = (int) config('assessments.max_attempts_per_day', 6);
        if ($max <= 0) {
            return ['max' => 0, 'remaining' => null, 'next_attempt_at' => null];
        }

        $recent = $student->assessmentSessions()
            ->where('created_at', '>', now()->subDay())
            ->orderBy('created_at')
            ->get(['created_at'])
            ->map(fn (AssessmentSession $session): Carbon => $session->created_at);
        $remaining = max(0, $max - $recent->count());

        return [
            'max' => $max,
            'remaining' => $remaining,
            // تتحرر أول خانة عندما تخرج أقدم محاولة من نافذة الـ24 ساعة
            'next_attempt_at' => $remaining === 0 ? $recent->get($recent->count() - $max)->copy()->addDay()->toIso8601String() : null,
        ];
    }

    /** @return array<int, string> نصوص أسئلة سابقة للطالب في المهارة والمستوى نفسيهما، الأحدث أولًا */
    private function previousPrompts(StudentProfile $student, int $skillId, string $level): array
    {
        $limit = max(0, (int) config('assessments.avoid_previous_questions', 30));
        if ($limit === 0) {
            return [];
        }

        return $student->assessmentSessions()
            ->where('skill_id', $skillId)
            ->where('requested_level', $level)
            ->latest('id')
            ->limit(10)
            ->get(['id', 'questions'])
            ->flatMap(fn (AssessmentSession $session): array => array_column($session->questions ?? [], 'prompt'))
            ->filter(fn ($prompt): bool => is_string($prompt) && $prompt !== '')
            ->unique()
            ->take($limit)
            ->map(fn (string $prompt): string => mb_substr($prompt, 0, 300))
            ->values()
            ->all();
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
