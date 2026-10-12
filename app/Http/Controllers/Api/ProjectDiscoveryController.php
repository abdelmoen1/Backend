<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\IntakeResponse;
use App\Models\IntakeSession;
use App\Models\ProjectRequest;
use App\Services\AI\DiscoveryRequirements;
use App\Services\AI\GeminiFailure;
use App\Services\AI\ProjectDiscoveryException;
use App\Services\AI\ProjectDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectDiscoveryController extends Controller
{
    private const MAX_DISCOVERY_QUESTIONS = 50;

    /** عدد آخر الأدوار المرسلة كاملة إلى Gemini؛ ما قبلها ممثل في الحالة المنظمة. */
    private const PROMPT_HISTORY_LIMIT = 12;

    public const REVISION_PREFIX = 'revision_';

    public function nextQuestion(
        Request $request,
        int $projectRequest,
        ProjectDiscoveryService $discovery
    ): JsonResponse {
        return $this->advance($request, $projectRequest, $discovery, 'next-question', false);
    }

    /**
     * يطلب الخطوة التالية من Gemini ويحفظها. إجابة المؤسسة تُحفظ قبل الوصول إلى هنا، لذا فشل
     * Gemini لا يمس المحفوظ، وإعادة المحاولة تعالج نفس المدخلات دون تكرار الإجابة.
     */
    private function advance(
        Request $request,
        int $projectRequest,
        ProjectDiscoveryService $discovery,
        string $endpoint,
        bool $answerSaved
    ): JsonResponse {
        $record = $this->ownedProjectRequest($request, $projectRequest);
        if ($response = $this->ensureDiscoverable($record)) {
            return $response;
        }

        $session = $record->intakeSession()->firstOrCreate([], [
            'status' => IntakeSession::STATUS_ACTIVE,
            'current_state' => [],
        ]);

        if ($session->status === IntakeSession::STATUS_COMPLETED) {
            return $this->discoveryResponse($session, 'complete', null, $record);
        }

        $pendingQuestion = $this->pendingQuestion($session);
        if ($pendingQuestion) {
            return $this->discoveryResponse($session, 'question', $pendingQuestion, $record);
        }

        if ($session->responses()->count() >= self::MAX_DISCOVERY_QUESTIONS) {
            return response()->json([
                'success' => false,
                'message' => 'توقفت جلسة الاكتشاف عند حد الأمان. يرجى التواصل مع الدعم لمتابعة الطلب.',
            ], 409);
        }

        $input = $this->newInput($record, $session);
        $context = $this->projectContext($record, $session, $input);
        $startedAt = microtime(true);

        try {
            $result = $this->reviewStep($discovery, $session, $context, $input, $discovery->nextStep($context));
        } catch (ProjectDiscoveryException $exception) {
            return $this->discoveryFailureResponse($exception, $record, $session, $input, $endpoint, $answerSaved, $startedAt);
        }

        $state = $this->persistNextStep($record, $session, $result, $input);

        Log::info('Project discovery step completed.', [
            'endpoint' => $endpoint,
            'project_request_id' => $record->id,
            'session_id' => $session->id,
            'status' => $state['status'],
            'requirement_updates' => count($result['updates'] ?? []),
            'duration_ms' => $this->elapsedMs($startedAt),
            'gemini_attempts' => Context::getHidden('gemini_attempts'),
        ]);

        return $this->discoveryResponse(
            $state['session'],
            $state['status'],
            $state['question'],
            $record->fresh()
        );
    }

    public function answer(
        Request $request,
        int $projectRequest,
        ProjectDiscoveryService $discovery
    ): JsonResponse {
        $record = $this->ownedProjectRequest($request, $projectRequest);
        if ($response = $this->ensureDiscoverable($record)) {
            return $response;
        }

        $validated = $request->validate([
            'question_id' => ['required', 'string', 'max:255'],
            'selected_answer' => ['present'],
            'custom_answer' => ['nullable', 'string', 'max:2000'],
        ], [
            'question_id.*' => 'تعذر تحديد السؤال الحالي. حدّث الصفحة وحاول مرة أخرى.',
            'selected_answer.present' => 'أكمل إجابتك للمتابعة.',
            'custom_answer.max' => 'الإجابة طويلة جدًا؛ الحد الأقصى 2000 حرف.',
            'custom_answer.*' => 'نص الإجابة غير صالح.',
        ]);
        if (is_string($validated['custom_answer'] ?? null)) {
            $validated['custom_answer'] = trim($validated['custom_answer']) === ''
                ? null
                : trim($validated['custom_answer']);
        }

        $session = $record->intakeSession;
        if (! $session) {
            return $this->staleAnswerResponse();
        }
        if ($session->status === IntakeSession::STATUS_COMPLETED) {
            return $this->discoveryResponse($session, 'complete', null, $record);
        }

        $answerRecord = $session->responses()
            ->where('question_id', $validated['question_id'])
            ->first();
        if (! $answerRecord) {
            return $this->staleAnswerResponse();
        }

        if ($answerRecord->answered_at) {
            if (! $this->sameAnswer($answerRecord, $validated)) {
                return $this->staleAnswerResponse();
            }

            // إعادة إرسال نفس الإجابة (مثل إعادة المحاولة بعد فشل جزئي) آمنة ولا تكرر الحفظ
            return $this->advance($request, $projectRequest, $discovery, 'answer', true);
        }

        if ($session->current_question_id !== $answerRecord->question_id) {
            return $this->staleAnswerResponse();
        }

        $this->validateAnswer($answerRecord, $validated);

        $saved = DB::transaction(function () use ($validated, $answerRecord, $record, $session) {
            $lockedRecord = ProjectRequest::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertProjectIsDiscoverable($lockedRecord);

            $lockedSession = IntakeSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAnswer = $lockedSession->responses()
                ->whereKey($answerRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSession->status === IntakeSession::STATUS_COMPLETED) {
                return false;
            }

            if ($lockedAnswer->answered_at) {
                return $this->sameAnswer($lockedAnswer, $validated);
            }

            if ($lockedSession->current_question_id !== $lockedAnswer->question_id) {
                return false;
            }

            $lockedAnswer->selected_answer = $validated['selected_answer'];
            $lockedAnswer->custom_answer = $validated['custom_answer'] ?? null;
            $lockedAnswer->answered_at = now();
            $lockedAnswer->save();

            $currentState = $lockedSession->current_state ?? [];
            $currentState['confirmed_requirements'] = DiscoveryRequirements::appendConfirmedAnswer(
                $currentState['confirmed_requirements'] ?? [],
                $lockedAnswer
            );
            $lockedSession->current_question_id = null;
            $lockedSession->current_state = array_merge($currentState, [
                'answered_count' => $lockedSession->responses()
                    ->whereNotNull('answered_at')
                    ->count(),
                'last_answered_question_id' => $lockedAnswer->question_id,
            ]);
            $lockedSession->save();

            $lockedRecord->status = ProjectRequestStatus::INTAKE_IN_PROGRESS;
            $lockedRecord->save();

            return true;
        });

        if (! $saved) {
            return $this->staleAnswerResponse();
        }

        // الإجابة محفوظة الآن؛ إذا فشل طلب Gemini التالي تبقى الإجابة وتُعالج عند إعادة المحاولة
        return $this->advance($request, $projectRequest, $discovery, 'answer', true);
    }

    /**
     * مدخلات المؤسسة التي لم تُدمج بعد في الحالة المنظمة (نموذج المشروع + إجابات + طلبات تعديل).
     *
     * @return array{entries: array, through: int, source: array{type: string, sequence: ?int}, revision: bool}
     */
    private function newInput(ProjectRequest $record, IntakeSession $session): array
    {
        $requirements = DiscoveryRequirements::from($session->current_state ?? []);
        $answered = $session->responses()
            ->whereNotNull('answered_at')
            ->where('sequence', '>', $requirements['processed_through'])
            ->get();

        $entries = [];
        if (! $requirements['form_processed']) {
            $entries[] = array_filter([
                'kind' => 'project_form',
                'project_name' => $record->title,
                'short_title' => $record->intake_data['short_title'] ?? null,
                'problem_description' => $record->problem_description,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        foreach ($answered as $response) {
            $entries[] = array_filter([
                'kind' => $this->isRevisionTurn($response) ? 'proposal_change_request' : 'answer',
                'sequence' => $response->sequence,
                'question' => $response->question_text,
                'answer' => $this->answerForPrompt($response),
                'free_text' => $response->custom_answer,
            ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        }

        $latest = $answered->last();

        return [
            'entries' => $entries,
            'through' => $latest?->sequence ?? $requirements['processed_through'],
            'source' => [
                'type' => $latest === null
                    ? 'project_form'
                    : ($this->isRevisionTurn($latest) ? 'revision' : 'answer'),
                'sequence' => $latest?->sequence,
            ],
            'revision' => $latest !== null && $this->isRevisionTurn($latest),
        ];
    }

    private function projectContext(ProjectRequest $record, IntakeSession $session, array $input): array
    {
        $record->loadMissing('organization');
        $allHistory = $session->responses()
            ->whereNotNull('answered_at')
            ->get()
            ->map(function (IntakeResponse $response): array {
                $selectedValues = is_array($response->selected_answer)
                    ? $response->selected_answer
                    : [$response->selected_answer];
                $labelsByValue = array_column($response->options ?? [], 'label_ar', 'value');
                $selectedLabels = $response->question_type === 'yes_no'
                    && is_bool($response->selected_answer)
                    ? [$response->selected_answer ? 'نعم' : 'لا']
                    : array_values(array_filter(array_map(
                        fn (mixed $value): ?string => is_string($value)
                            ? ($labelsByValue[$value] ?? null)
                            : null,
                        $selectedValues
                    )));

                return [
                    'question_id' => $response->question_id,
                    'question' => $response->question_text,
                    'type' => $response->question_type,
                    'options' => $response->options ?? [],
                    'selected_answer' => $response->selected_answer,
                    'selected_option_labels' => $selectedLabels,
                    'custom_answer' => $response->custom_answer,
                ];
            })
            ->values()
            ->all();
        // السجل الكامل محفوظ في قاعدة البيانات؛ نرسل آخر الأدوار فقط لأن ما سبقها ممثل في requirements_state
        $history = array_slice($allHistory, -self::PROMPT_HISTORY_LIMIT);
        $latestAnswer = $allHistory === [] ? null : $allHistory[array_key_last($allHistory)];

        $currentState = $session->current_state ?? [];
        $requirements = DiscoveryRequirements::from($currentState);
        $tracked = DiscoveryRequirements::isTracked($currentState);
        $missingCore = DiscoveryRequirements::missingCoreAreas($requirements);

        $context = [
            'mode' => $input['revision'] ? 'revision' : 'discovery',
            'organization' => array_filter([
                'type' => $record->organization?->organization_type,
                'industry' => $record->organization?->industry,
                'description' => $record->organization?->description,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'project' => [
                'title' => $record->title,
                'problem' => $record->problem_description,
                'current_process' => $record->current_process ?? [],
                'users' => $record->target_users ?? [],
                'goals' => $record->desired_outcomes ?? [],
                'known_constraints' => array_filter([
                    'special_requirements' => $record->special_requirements,
                    'existing_tools' => $record->existing_tools ?? [],
                    'deadline' => $record->deadline?->toDateString(),
                    'priority' => $record->priority,
                    'budget_range' => $record->budget_range,
                ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
                'organization_notes' => $record->intake_data ?? [],
            ],
            'discovery_areas' => DiscoveryRequirements::AREAS,
            'requirements_state' => DiscoveryRequirements::forPrompt($requirements),
            'new_organization_input' => $input['entries'],
            'readiness' => [
                'missing_core_areas' => DiscoveryRequirements::labels($missingCore),
                'ready_for_proposal' => $tracked && $missingCore === [],
                'answered_questions' => count($allHistory),
            ],
            'known_requirements' => [
                'goals' => $record->desired_outcomes ?? [],
                'constraints' => $record->special_requirements,
                'confirmed_discovery_answers' => $history,
                'custom_answers' => array_values(array_filter(array_column(
                    $history,
                    'custom_answer'
                ))),
            ],
            'structured_understanding' => [
                'source' => 'confirmed_user_answers_only',
                'instruction' => 'هذه متطلبات مؤكدة من إجابات المؤسسة فقط. لا تضف إليها استنتاجات أو افتراضات. استخدم سجل history الكامل لمعرفة تسلسل الأسئلة والإجابات وآخر إجابة.',
                'confirmed_requirements' => $this->confirmedRequirements($session),
            ],
            'already_asked_questions' => array_map(
                fn (IntakeResponse $response): array => [
                    'id' => $response->question_id,
                    'text_ar' => $response->question_text,
                ],
                $session->responses()->get()->all()
            ),
            'latest_answer' => $latestAnswer,
            'remaining_unknowns' => [
                'instruction' => 'استنتج من سياق المشروع وسجل الاكتشاف المعلومات المؤثرة التي لا تزال غير معروفة أو ملتبسة. لا تفترض أن كل مجال أدناه مجهول ولا تسأل عن معلومة حُسمت بالفعل.',
                'areas_to_assess' => [
                    'الأدوار والمستخدمون وصلاحياتهم',
                    'سير العمل والقواعد التجارية المهمة',
                    'النتائج المطلوبة ومعايير النجاح',
                    'القيود والتكاملات التي تؤثر في النطاق',
                ],
            ],
            'history' => $history,
        ];

        if ($input['revision'] && is_array($record->proposal)) {
            $context['current_proposal'] = $this->proposalSummary($record->proposal);
        }

        return $context;
    }

    /**
     * يراجع خطوة Gemini قبل حفظها: إنهاء قبل فهم المحاور الأساسية، أو سؤال سبق طرحه بنفس النص.
     * في الحالتين نطلب من Gemini تصحيحًا واحدًا فقط حتى لا تنشأ حلقة أو تكلفة غير محدودة.
     * إن أصر على الإنهاء نقبله ونسجل المحاور الناقصة كنقاط مفتوحة؛ أما السؤال المكرر فلا يُعرض
     * أبدًا ويُعاد خطأ قابل لإعادة المحاولة.
     */
    private function reviewStep(
        ProjectDiscoveryService $discovery,
        IntakeSession $session,
        array $context,
        array $input,
        array $result
    ): array {
        $projected = array_key_exists('updates', $result)
            ? $this->projectRequirements($session, $result, $input)
            : null;
        $missing = $result['status'] === 'complete' && $projected !== null
            ? DiscoveryRequirements::missingCoreAreas($projected)
            : [];
        $repeated = $result['status'] === 'question'
            && $this->repeatsPreviousQuestion($session, $result['question']);

        if ($missing === [] && ! $repeated) {
            return $result;
        }

        Log::info('Gemini discovery step needs one correction.', [
            'provider' => 'gemini',
            'session_id' => $session->id,
            'issue' => $repeated ? 'repeated_question' : 'premature_completion',
            'missing_core_areas' => $missing,
        ]);

        // التصحيح يرى ما استُخرج للتو، فلا يُرسل الإدخال الجديد مرة أخرى
        $retryContext = $context;
        if ($projected !== null) {
            $retryContext['requirements_state'] = DiscoveryRequirements::forPrompt($projected);
            $retryContext['new_organization_input'] = [];
            $retryContext['readiness']['missing_core_areas'] = DiscoveryRequirements::labels(
                DiscoveryRequirements::missingCoreAreas($projected)
            );
            $retryContext['readiness']['ready_for_proposal'] = $missing === []
                && DiscoveryRequirements::missingCoreAreas($projected) === [];
        }
        if ($missing !== []) {
            $retryContext['completion_rejected'] = [
                'missing_core_areas' => array_map(
                    fn (string $area): array => ['area' => $area, 'label' => DiscoveryRequirements::AREAS[$area]],
                    $missing
                ),
                'instruction' => 'لم تتضح هذه المحاور الأساسية بعد. اسأل سؤالًا واحدًا عن أهم محور ناقص منها بدل إنهاء الاكتشاف.',
            ];
        }
        if ($repeated) {
            $retryContext['repeated_question_rejected'] = [
                'question' => $result['question']['text_ar'],
                'instruction' => 'سبق طرح هذا السؤال في المحادثة. لا تكرره ولو بصياغة أخرى؛ اسأل عن معلومة مختلفة ما زالت ناقصة، أو أنهِ الاكتشاف إن كانت المعلومات كافية.',
            ];
        }

        try {
            $retry = $discovery->nextStep($retryContext);
        } catch (ProjectDiscoveryException $exception) {
            if ($repeated) {
                throw $exception;
            }
            $retry = null;
        }

        if (
            $retry !== null
            && $retry['status'] === 'question'
            && ! $this->repeatsPreviousQuestion($session, $retry['question'])
        ) {
            return $this->mergeCorrection($result, $retry);
        }

        if ($repeated && $retry['status'] !== 'complete') {
            throw ProjectDiscoveryException::because(GeminiFailure::REPEATED_QUESTION);
        }

        return $this->withOpenQuestionsForMissingCore(
            $session,
            $repeated ? $this->mergeCorrection($result, $retry) : $result,
            $input
        );
    }

    private function projectRequirements(IntakeSession $session, array $result, array $input): array
    {
        return DiscoveryRequirements::apply(
            DiscoveryRequirements::from($session->current_state ?? []),
            $result['updates'] ?? [],
            $result['coverage'] ?? [],
            $input['source']
        );
    }

    /** يدمج نتيجة التصحيح مع الخطوة الأولى: ما استُخرج في الخطوتين، ورد الإقرار الأول. */
    private function mergeCorrection(array $first, array $retry): array
    {
        $merged = [
            'status' => $retry['status'],
            'coverage' => array_merge($first['coverage'] ?? [], $retry['coverage'] ?? []),
        ];
        if ($retry['status'] === 'question') {
            $merged['question'] = $retry['question'];
        }
        // وجود updates يعني تحديث الحالة المنظمة؛ لا نضيفه لاستجابة قديمة الشكل
        if (array_key_exists('updates', $first) || array_key_exists('updates', $retry)) {
            $merged['updates'] = array_merge($first['updates'] ?? [], $retry['updates'] ?? []);
        }
        $reply = $first['reply'] ?? $retry['reply'] ?? null;
        if ($reply !== null) {
            $merged['reply'] = $reply;
        }

        return $merged;
    }

    private function withOpenQuestionsForMissingCore(IntakeSession $session, array $result, array $input): array
    {
        if (! array_key_exists('updates', $result)) {
            return $result;
        }

        $missing = DiscoveryRequirements::missingCoreAreas($this->projectRequirements($session, $result, $input));
        foreach ($missing as $area) {
            $result['updates'][] = [
                'area' => $area,
                'kind' => 'open_question',
                'text' => 'لم تتوفر معلومات كافية بعد عن '.DiscoveryRequirements::AREAS[$area].'.',
                'priority' => 'unspecified',
                'replaces' => [],
            ];
        }

        return $result;
    }

    private function confirmedRequirements(
        IntakeSession $session
    ): array {
        $state = $session->current_state ?? [];
        $confirmedRequirements = $state['confirmed_requirements'] ?? null;

        if (is_array($confirmedRequirements)) {
            return array_values($confirmedRequirements);
        }

        return $session->responses()
            ->whereNotNull('answered_at')
            ->get()
            ->map(fn (IntakeResponse $response): array => DiscoveryRequirements::confirmedAnswer($response))
            ->all();
    }

    /** تكرار النص هو التكرار الحقيقي؛ تكرار المعرّف وحده يُعالج في uniqueQuestionId. */
    private function repeatsPreviousQuestion(IntakeSession $session, array $question): bool
    {
        $normalizedText = mb_strtolower(trim($question['text_ar']));

        return $session->responses()->get()->contains(
            fn (IntakeResponse $response): bool => mb_strtolower(trim($response->question_text)) === $normalizedText
        );
    }

    /**
     * معرّف السؤال يولّده Gemini ولا معنى له خارج الجلسة؛ إن سبق استخدامه (أو بدا كدور تعديل)
     * نمنحه معرّفًا جديدًا بدل رفض سؤال صالح.
     */
    private function uniqueQuestionId(IntakeSession $session, string $id): string
    {
        $used = $session->responses()->pluck('question_id')->all();
        if (! in_array($id, $used, true) && ! str_starts_with($id, self::REVISION_PREFIX)) {
            return $id;
        }

        $base = mb_substr(str_starts_with($id, self::REVISION_PREFIX) ? 'q_'.$id : $id, 0, 200);
        $suffix = count($used) + 1;
        while (in_array("{$base}_{$suffix}", $used, true)) {
            $suffix++;
        }

        return "{$base}_{$suffix}";
    }

    private function persistNextStep(
        ProjectRequest $record,
        IntakeSession $session,
        array $result,
        array $input
    ): array {
        return DB::transaction(function () use ($record, $session, $result, $input): array {
            $lockedRecord = ProjectRequest::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertProjectIsDiscoverable($lockedRecord);

            $lockedSession = IntakeSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSession->status === IntakeSession::STATUS_COMPLETED) {
                return ['session' => $lockedSession, 'status' => 'complete', 'question' => null];
            }

            $existingPendingQuestion = $this->pendingQuestion($lockedSession);
            if ($existingPendingQuestion) {
                return [
                    'session' => $lockedSession,
                    'status' => 'question',
                    'question' => $existingPendingQuestion,
                ];
            }

            $state = $lockedSession->current_state ?? [];

            // المتطلبات المستخرجة تُحفظ مع الخطوة التالية في نفس المعاملة
            if (array_key_exists('updates', $result)) {
                $requirements = DiscoveryRequirements::apply(
                    DiscoveryRequirements::from($state),
                    $result['updates'],
                    $result['coverage'] ?? [],
                    $input['source']
                );
                $requirements['processed_through'] = max($requirements['processed_through'], $input['through']);
                $requirements['form_processed'] = true;
                $state['requirements'] = $requirements;
            }

            if ($result['status'] === 'complete') {
                if (isset($result['reply'])) {
                    $state['completion_reply'] = $result['reply'];
                }
                $lockedSession->status = IntakeSession::STATUS_COMPLETED;
                $lockedSession->current_question_id = null;
                $lockedSession->current_state = array_merge($state, ['completed' => true]);
                $lockedSession->save();

                $lockedRecord->status = ProjectRequestStatus::ANALYZING;
                $lockedRecord->save();

                return ['session' => $lockedSession, 'status' => 'complete', 'question' => null];
            }

            $question = $result['question'];
            $question['id'] = $this->uniqueQuestionId($lockedSession, $question['id']);
            $sequence = (int) $lockedSession->responses()->max('sequence') + 1;
            $lockedSession->responses()->create([
                'sequence' => $sequence,
                'question_id' => $question['id'],
                'question_text' => $question['text_ar'],
                'question_type' => $question['type'],
                'options' => $question['options'],
            ]);

            $turn = array_filter([
                'reply' => $result['reply'] ?? null,
                'area' => $question['area'] ?? null,
                'why' => $question['why_ar'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
            if ($turn !== []) {
                $state['turns'][$question['id']] = $turn;
            }

            $lockedSession->status = IntakeSession::STATUS_ACTIVE;
            $lockedSession->current_question_id = $question['id'];
            $lockedSession->current_state = array_merge($state, [
                'answered_count' => $sequence - 1,
            ]);
            $lockedSession->save();

            $lockedRecord->status = ProjectRequestStatus::INTAKE_IN_PROGRESS;
            $lockedRecord->save();

            return ['session' => $lockedSession, 'status' => 'question', 'question' => $question];
        });
    }

    private function pendingQuestion(IntakeSession $session): ?array
    {
        if (! $session->current_question_id) {
            return null;
        }

        $response = $session->responses()
            ->where('question_id', $session->current_question_id)
            ->whereNull('answered_at')
            ->first();

        if (! $response) {
            return null;
        }

        return $this->questionPayload($session, $response);
    }

    private function questionPayload(IntakeSession $session, IntakeResponse $response): array
    {
        $turn = $session->current_state['turns'][$response->question_id] ?? [];

        return array_filter([
            'id' => $response->question_id,
            'text_ar' => $response->question_text,
            'type' => $response->question_type,
            'options' => $response->options ?? [],
            'area' => $turn['area'] ?? null,
            'why_ar' => $turn['why'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * الإجابة الحرة مقبولة دائمًا: لأسئلة الاختيار والرقم والتاريخ ونعم/لا يمكن ترك
     * selected_answer فارغًا وكتابة الإجابة في custom_answer.
     */
    private function validateAnswer(IntakeResponse $question, array $answer): void
    {
        $type = $question->question_type;
        $options = $question->options ?? [];
        $optionValues = array_column($options, 'value');
        $selected = $answer['selected_answer'];
        $hasCustomAnswer = is_string($answer['custom_answer'] ?? null)
            && trim($answer['custom_answer']) !== '';
        $emptySelection = $selected === null || $selected === '' || $selected === [];
        $textTypes = ['short_text', 'long_text'];

        if (! in_array($type, $textTypes, true) && $emptySelection) {
            if (! $hasCustomAnswer) {
                throw ValidationException::withMessages([
                    'selected_answer' => ['اختر إجابة أو اكتبها بكلماتك.'],
                ]);
            }

            return;
        }

        $rules = match ($type) {
            'single_choice' => ['required', 'string', Rule::in($optionValues)],
            'multi_choice' => [
                'required',
                'array',
                'min:1',
                'max:'.count($optionValues),
            ],
            'short_text' => ['required', 'string', 'min:1', 'max:2000'],
            'long_text' => ['required', 'string', 'min:1', 'max:10000'],
            'number' => ['required', 'numeric'],
            'date' => ['required', 'date'],
            'yes_no' => ['required', 'boolean'],
            default => [],
        };
        $validator = Validator::make([
            'selected_answer' => $selected,
        ], [
            'selected_answer' => $rules === []
                ? ['prohibited']
                : $rules,
            'selected_answer.*' => $type === 'multi_choice'
                ? ['required', 'string', 'distinct', Rule::in($optionValues)]
                : [],
        ], [
            'selected_answer.required' => 'أكمل إجابتك للمتابعة.',
            'selected_answer.in' => 'الخيار المحدد غير متاح لهذا السؤال.',
            'selected_answer.*.in' => 'أحد الخيارات المحددة غير متاح لهذا السؤال.',
            'selected_answer.*.distinct' => 'لا يمكن تكرار نفس الخيار.',
            'selected_answer.numeric' => 'أدخل رقمًا صحيحًا أو اكتب إجابتك بكلماتك.',
            'selected_answer.date' => 'أدخل تاريخًا صحيحًا أو اكتب إجابتك بكلماتك.',
            'selected_answer.boolean' => 'اختر نعم أو لا، أو اكتب إجابتك بكلماتك.',
            'selected_answer.max' => 'الإجابة طويلة جدًا.',
            'selected_answer.prohibited' => 'نوع هذا السؤال غير مدعوم.',
            'selected_answer.*' => 'تحقق من إجابتك وحاول مرة أخرى.',
        ]);

        $validator->validate();

        $selectedValues = is_array($selected) ? $selected : [$selected];
        $selectedCustomOptions = array_filter(
            $options,
            fn (array $option): bool => in_array($option['value'], $selectedValues, true)
                && ($option['allows_custom_text'] ?? false)
        );

        if ($selectedCustomOptions !== [] && ! $hasCustomAnswer) {
            throw ValidationException::withMessages([
                'custom_answer' => ['يرجى توضيح إجابتك في حقل أخرى.'],
            ]);
        }
    }

    private function sameAnswer(IntakeResponse $savedAnswer, array $answer): bool
    {
        return $savedAnswer->selected_answer === $answer['selected_answer']
            && $savedAnswer->custom_answer === ($answer['custom_answer'] ?? null);
    }

    private function discoveryResponse(
        IntakeSession $session,
        string $status,
        ?array $question = null,
        ?ProjectRequest $record = null
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $this->discoveryPayload($session, $status, $question, $record),
        ]);
    }

    private function discoveryPayload(
        IntakeSession $session,
        string $status,
        ?array $question = null,
        ?ProjectRequest $record = null
    ): array {
        $state = $session->current_state ?? [];
        $skipped = is_array($state['skipped_questions'] ?? null) ? $state['skipped_questions'] : [];

        return [
            'session_id' => $session->id,
            'status' => $status,
            'question' => $question,
            'history' => $session->responses()
                ->get()
                ->map(fn (IntakeResponse $response): array => [
                    'sequence' => $response->sequence,
                    'kind' => $this->isRevisionTurn($response) ? 'revision' : 'question',
                    'question' => $this->questionPayload($session, $response),
                    'assistant_reply' => $state['turns'][$response->question_id]['reply'] ?? null,
                    'selected_answer' => $response->selected_answer,
                    'custom_answer' => $response->custom_answer,
                    'answered_at' => $response->answered_at?->toISOString(),
                    'skipped' => in_array($response->question_id, $skipped, true),
                ])
                ->all(),
            'requirements' => DiscoveryRequirements::publicView($state),
            'completion_message' => $status === 'complete'
                ? ($state['completion_reply'] ?? null)
                : null,
            'project_status' => $record?->status,
            'has_proposal' => is_array($record?->proposal),
        ];
    }

    private function staleAnswerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'هذا السؤال لم يعد الحالي. يرجى تحديث حالة الاكتشاف والمحاولة مجددًا.',
        ], 409);
    }

    /**
     * فشل خطوة الذكاء الاصطناعي: سطر سجل واحد يحدد المرحلة والسبب، واستجابة 503 آمنة تحمل
     * رمزًا عامًا ومعرّف الطلب والمحادثة المحفوظة (status=pending) حتى تعرضها الواجهة وتتابع
     * عبر next-question دون إعادة إرسال الإجابة. نستخدم 503 دائمًا لأن بعض البوابات تستبدل
     * محتوى 502/504 بصفحاتها فتضيع الرسالة وترويسات CORS.
     */
    private function discoveryFailureResponse(
        ProjectDiscoveryException $exception,
        ProjectRequest $record,
        IntakeSession $session,
        array $input,
        string $endpoint,
        bool $answerSaved,
        float $startedAt
    ): JsonResponse {
        $failure = $exception->failure;
        $requestId = Context::get('request_id');

        Log::warning('Project discovery AI step failed.', array_filter([
            'endpoint' => $endpoint,
            'stage' => in_array($failure->reason, [GeminiFailure::INVALID_OUTPUT, GeminiFailure::REPEATED_QUESTION], true)
                ? 'ai_response_validation'
                : 'ai_request',
            'project_request_id' => $record->id,
            'session_id' => $session->id,
            'error_code' => $exception->errorCode(),
            'model' => (string) config('services.gemini.model'),
            'gemini_attempts' => Context::getHidden('gemini_attempts'),
            'answer_persisted' => $answerSaved,
            'unprocessed_answers' => count(array_filter(
                $input['entries'],
                fn (array $entry): bool => $entry['kind'] !== 'project_form'
            )),
            'duration_ms' => $this->elapsedMs($startedAt),
            ...$failure->diagnostic(),
        ], fn (mixed $value): bool => $value !== null));

        $payload = [
            'success' => false,
            'message' => $exception->getMessage(),
            'error' => [
                'code' => $exception->errorCode(),
                'retryable' => $exception->retryable(),
                'retry_after' => $exception->retryAfter(),
            ],
            'request_id' => $requestId,
            'data' => $this->discoveryPayload($session->fresh() ?? $session, 'pending', null, $record->fresh() ?? $record),
        ];
        if ($endpoint === 'answer') {
            $payload['answer_saved'] = $answerSaved;
        }
        if (config('app.debug')) {
            $payload['debug'] = $exception->providerDiagnostic;
        }

        $response = response()->json($payload, 503);
        if ($exception->retryAfter() !== null) {
            $response->headers->set('Retry-After', (string) $exception->retryAfter());
        }

        return $response;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function ensureDiscoverable(ProjectRequest $record): ?JsonResponse
    {
        if (in_array($record->status, [
            ProjectRequestStatus::APPROVED,
            ProjectRequestStatus::REJECTED,
            ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن متابعة اكتشاف طلب مغلق.',
            ], 422);
        }

        return null;
    }

    private function assertProjectIsDiscoverable(ProjectRequest $record): void
    {
        if (in_array($record->status, [
            ProjectRequestStatus::APPROVED,
            ProjectRequestStatus::REJECTED,
            ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY,
        ], true)) {
            throw ValidationException::withMessages([
                'project_request' => ['لا يمكن متابعة اكتشاف طلب مغلق.'],
            ]);
        }
    }

    private function ownedProjectRequest(Request $request, int $projectRequest): ProjectRequest
    {
        $user = $request->user();
        abort_unless(
            $user->role === 'organization' && $user->organizationProfile,
            403,
            'Only organizations can access project requests.'
        );

        return $user->organizationProfile->projectRequests()
            ->with('organization')
            ->findOrFail($projectRequest);
    }

    private function isRevisionTurn(IntakeResponse $response): bool
    {
        return str_starts_with($response->question_id, self::REVISION_PREFIX);
    }

    private function answerForPrompt(IntakeResponse $response): mixed
    {
        $answer = $response->selected_answer;
        if ($response->question_type === 'yes_no' && is_bool($answer)) {
            return $answer ? 'نعم' : 'لا';
        }

        $labelsByValue = array_column($response->options ?? [], 'label_ar', 'value');
        if (is_array($answer)) {
            return array_values(array_map(
                fn (mixed $value): mixed => is_string($value) ? ($labelsByValue[$value] ?? $value) : $value,
                $answer
            ));
        }

        return is_string($answer) ? ($labelsByValue[$answer] ?? $answer) : $answer;
    }

    /** ملخص محدود الحجم للمقترح الحالي يُرسل في وضع التعديل فقط. */
    private function proposalSummary(array $proposal): array
    {
        return array_filter([
            'project_overview' => $proposal['project_overview'] ?? null,
            'proposed_solution' => $proposal['proposed_solution'] ?? null,
            'functional_scope' => $proposal['functional_scope'] ?? null,
            'out_of_scope' => $proposal['out_of_scope'] ?? null,
            'milestones' => $proposal['milestones'] ?? null,
            'estimated_duration' => $proposal['estimated_duration'] ?? null,
            'estimated_cost' => $proposal['estimated_cost'] ?? null,
            'operational_cost' => $proposal['operational_cost'] ?? null,
            'assumptions' => $proposal['assumptions'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
