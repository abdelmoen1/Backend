<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\IntakeResponse;
use App\Models\IntakeSession;
use App\Models\ProjectRequest;
use App\Services\AI\ProjectDiscoveryException;
use App\Services\AI\ProjectDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectDiscoveryController extends Controller
{
    private const MAX_DISCOVERY_QUESTIONS = 50;

    public function nextQuestion(
        Request $request,
        int $projectRequest,
        ProjectDiscoveryService $discovery
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
            return $this->discoveryResponse($session, 'complete');
        }

        $pendingQuestion = $this->pendingQuestion($session);
        if ($pendingQuestion) {
            return $this->discoveryResponse($session, 'question', $pendingQuestion);
        }

        if ($session->responses()->count() >= self::MAX_DISCOVERY_QUESTIONS) {
            return response()->json([
                'success' => false,
                'message' => 'توقفت جلسة الاكتشاف عند حد الأمان. يرجى التواصل مع الدعم لمتابعة الطلب.',
            ], 409);
        }

        try {
            $result = $discovery->nextStep($this->projectContext($record, $session));
        } catch (ProjectDiscoveryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 503);
        }

        if ($result['status'] === 'question' && $this->repeatsPreviousQuestion($session, $result['question'])) {
            report('Gemini returned a repeated project discovery question.');

            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء سؤال جديد للاكتشاف حاليًا. إجاباتك محفوظة، ويمكنك المحاولة مرة أخرى.',
            ], 503);
        }

        $state = $this->persistNextStep($record, $session, $result);

        return $this->discoveryResponse(
            $state['session'],
            $state['status'],
            $state['question']
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
        ]);

        $session = $record->intakeSession;
        if (! $session) {
            return $this->staleAnswerResponse();
        }
        if ($session->status === IntakeSession::STATUS_COMPLETED) {
            return $this->discoveryResponse($session, 'complete');
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

            return $this->nextQuestion($request, $projectRequest, $discovery);
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
            $currentState['confirmed_requirements'] = $this->appendConfirmedRequirement(
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

        return $this->nextQuestion($request, $projectRequest, $discovery);
    }

    private function projectContext(ProjectRequest $record, IntakeSession $session): array
    {
        $record->loadMissing('organization');
        $history = $session->responses()
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
            ->all();

        return [
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
                ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
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
            ->map(fn (IntakeResponse $response): array => $this->confirmedRequirement($response))
            ->all();
    }

    private function appendConfirmedRequirement(
        mixed $existingRequirements,
        IntakeResponse $response
    ): array {
        $requirements = is_array($existingRequirements)
            ? array_values($existingRequirements)
            : [];
        $requirement = $this->confirmedRequirement($response);

        foreach ($requirements as $index => $existingRequirement) {
            if (
                is_array($existingRequirement)
                && ($existingRequirement['question_id'] ?? null) === $requirement['question_id']
            ) {
                $requirements[$index] = array_merge(
                    $existingRequirement,
                    $requirement
                );

                return $requirements;
            }
        }

        $requirements[] = $requirement;

        return $requirements;
    }

    private function confirmedRequirement(IntakeResponse $response): array
    {
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

        $requirement = [
            'sequence' => $response->sequence,
            'question_id' => $response->question_id,
            'question' => $response->question_text,
            'answer' => $response->selected_answer,
            'selected_option_labels' => $selectedLabels,
            'source' => 'user_answer',
        ];
        if (is_string($response->custom_answer) && trim($response->custom_answer) !== '') {
            $requirement['custom_answer'] = $response->custom_answer;
        }

        return $requirement;
    }

    private function repeatsPreviousQuestion(IntakeSession $session, array $question): bool
    {
        $normalizedText = mb_strtolower(trim($question['text_ar']));

        return $session->responses()->get()->contains(
            fn (IntakeResponse $response): bool => $response->question_id === $question['id']
                || mb_strtolower(trim($response->question_text)) === $normalizedText
        );
    }

    private function persistNextStep(
        ProjectRequest $record,
        IntakeSession $session,
        array $result
    ): array {
        return DB::transaction(function () use ($record, $session, $result): array {
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

            if ($result['status'] === 'complete') {
                $lockedSession->status = IntakeSession::STATUS_COMPLETED;
                $lockedSession->current_question_id = null;
                $lockedSession->current_state = array_merge(
                    $lockedSession->current_state ?? [],
                    ['completed' => true]
                );
                $lockedSession->save();

                $lockedRecord->status = ProjectRequestStatus::ANALYZING;
                $lockedRecord->save();

                return ['session' => $lockedSession, 'status' => 'complete', 'question' => null];
            }

            $question = $result['question'];
            $sequence = (int) $lockedSession->responses()->max('sequence') + 1;
            $lockedSession->responses()->create([
                'sequence' => $sequence,
                'question_id' => $question['id'],
                'question_text' => $question['text_ar'],
                'question_type' => $question['type'],
                'options' => $question['options'],
            ]);
            $lockedSession->status = IntakeSession::STATUS_ACTIVE;
            $lockedSession->current_question_id = $question['id'];
            $lockedSession->current_state = array_merge(
                $lockedSession->current_state ?? [],
                ['answered_count' => $sequence - 1]
            );
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

        return [
            'id' => $response->question_id,
            'text_ar' => $response->question_text,
            'type' => $response->question_type,
            'options' => $response->options ?? [],
        ];
    }

    private function validateAnswer(IntakeResponse $question, array $answer): void
    {
        $type = $question->question_type;
        $options = $question->options ?? [];
        $optionValues = array_column($options, 'value');
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
            'selected_answer' => $answer['selected_answer'],
        ], [
            'selected_answer' => $rules === []
                ? ['prohibited']
                : $rules,
            'selected_answer.*' => $type === 'multi_choice'
                ? ['required', 'string', 'distinct', Rule::in($optionValues)]
                : [],
        ]);

        $validator->validate();

        $selectedValues = is_array($answer['selected_answer'])
            ? $answer['selected_answer']
            : [$answer['selected_answer']];
        $selectedCustomOptions = array_filter(
            $options,
            fn (array $option): bool => in_array($option['value'], $selectedValues, true)
                && ($option['allows_custom_text'] ?? false)
        );
        $hasCustomAnswer = is_string($answer['custom_answer'] ?? null)
            && trim($answer['custom_answer']) !== '';

        if ($selectedCustomOptions !== [] && ! $hasCustomAnswer) {
            throw ValidationException::withMessages([
                'custom_answer' => ['يرجى توضيح إجابتك في حقل أخرى.'],
            ]);
        }

        if ($selectedCustomOptions === [] && $hasCustomAnswer) {
            throw ValidationException::withMessages([
                'custom_answer' => ['لا يمكن إرسال إجابة مخصصة لهذا الخيار.'],
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
        ?array $question = null
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->id,
                'status' => $status,
                'question' => $question,
                'history' => $session->responses()
                    ->get()
                    ->map(fn (IntakeResponse $response): array => [
                        'sequence' => $response->sequence,
                        'question' => [
                            'id' => $response->question_id,
                            'text_ar' => $response->question_text,
                            'type' => $response->question_type,
                            'options' => $response->options ?? [],
                        ],
                        'selected_answer' => $response->selected_answer,
                        'custom_answer' => $response->custom_answer,
                        'answered_at' => $response->answered_at?->toISOString(),
                    ])
                    ->all(),
            ],
        ]);
    }

    private function staleAnswerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'هذا السؤال لم يعد الحالي. يرجى تحديث حالة الاكتشاف والمحاولة مجددًا.',
        ], 409);
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
}
