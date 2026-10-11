<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use JsonException;

class ProjectDiscoveryService
{
    private const QUESTION_TYPES = [
        'single_choice',
        'multi_choice',
        'short_text',
        'long_text',
        'number',
        'date',
        'yes_no',
    ];

    public function __construct(private readonly AIProvider $provider) {}

    public function nextStep(array $projectContext): array
    {
        $apiKey = is_string(config('services.gemini.api_key'))
            ? trim((string) config('services.gemini.api_key'))
            : '';

        try {
            $context = json_encode(
                $projectContext,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            $generationConfig = [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->responseSchema(),
                'maxOutputTokens' => 4096,
            ];
            $thinkingConfig = $this->thinkingConfig();
            if ($thinkingConfig !== []) {
                $generationConfig['thinkingConfig'] = $thinkingConfig;
            }

            $response = $this->provider->generateResponse([
                'systemInstruction' => [
                    'parts' => [['text' => self::systemInstruction()]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $context]],
                ]],
                'generationConfig' => $generationConfig,
            ]);
        } catch (AIProviderConfigurationException) {
            Log::error('Gemini project discovery is not configured.', [
                'provider' => 'gemini',
            ]);

            throw ProjectDiscoveryException::unavailable([
                'http_status' => null,
                'provider_status' => 'NOT_CONFIGURED',
            ]);
        } catch (ConnectionException) {
            Log::warning('Gemini project discovery connection failed.', [
                'provider' => 'gemini',
                'failure' => 'connection',
            ]);

            throw ProjectDiscoveryException::unavailable([
                'http_status' => 0,
                'provider_status' => 'CONNECTION_FAILURE',
            ]);
        } catch (JsonException) {
            Log::warning('Gemini project discovery context could not be encoded.', [
                'provider' => 'gemini',
            ]);

            throw ProjectDiscoveryException::unavailable([
                'provider_status' => 'CONTEXT_ENCODE_FAILURE',
            ]);
        }

        if ($response->failed()) {
            $providerError = $response->json('error', []);
            $providerError = is_array($providerError) ? $providerError : [];
            $providerStatus = is_string($providerError['status'] ?? null)
                ? $providerError['status']
                : null;
            $providerMessage = is_string($providerError['message'] ?? null)
                ? $providerError['message']
                : null;

            Log::warning('Gemini project discovery request failed.', [
                'provider' => 'gemini',
                'status' => $response->status(),
                'provider_status' => $providerStatus,
            ]);

            throw ProjectDiscoveryException::providerFailure(
                $response->status(),
                $providerStatus,
                $providerMessage,
                $apiKey
            );
        }

        $extracted = GeminiStructuredOutput::extract($response);
        $normalizedOutput = $this->normalizeStructuredOutput($extracted['data']);

        if ($normalizedOutput === null) {
            Log::warning('Gemini project discovery returned invalid structured output.', [
                'provider' => 'gemini',
                'finish_reason' => $extracted['diagnostic']['finish_reason'] ?? null,
                'parse_error' => $extracted['diagnostic']['parse_error'] ?? 'schema_validation',
                'candidate_count' => $extracted['diagnostic']['candidate_count'] ?? null,
                'text_part_count' => $extracted['diagnostic']['text_part_count'] ?? null,
            ]);

            throw ProjectDiscoveryException::unavailable([
                'http_status' => $response->status(),
                'provider_status' => 'INVALID_STRUCTURED_OUTPUT',
                'parse_error' => $extracted['diagnostic']['parse_error'] ?? 'schema_validation',
                'finish_reason' => $extracted['diagnostic']['finish_reason'] ?? null,
            ]);
        }

        return $normalizedOutput;
    }

    private function thinkingConfig(): array
    {
        $configured = config('services.gemini.thinking_budget');
        if ($configured !== null && $configured !== '') {
            return ['thinkingBudget' => (int) $configured];
        }

        $model = (string) config('services.gemini.model');
        // Only force zero thinking budget for known thinking/large models to avoid slowness
        if (preg_match('/(?:gemini-3\.5|thinking|pro)/i', $model) === 1) {
            return ['thinkingBudget' => 0];
        }

        return [];
    }

    private static function systemInstruction(): string
    {
        return implode("\n", [
            'You are "Traino", the GoTraniee business-analysis assistant. You talk with a non-technical organization to understand a real business problem well enough to write an honest project proposal for a student development team.',
            'Each turn you receive JSON with: the organization profile, the project form, requirements_state (cumulative structured requirements grouped by the 11 discovery areas, every item has an id), new_organization_input (organization messages NOT yet incorporated into requirements_state), recent conversation history, already_asked_questions, readiness, and mode.',
            'Step 1 - extract: read new_organization_input in the context of the whole conversation and put EVERY relevant piece of information into updates, even when one message covers several areas (for example problem, current process, users and constraints in one sentence). Each item is one short standalone statement written in the organization\'s language (Arabic by default). Do not repeat an item that already exists in requirements_state.',
            'Item kinds: fact = explicitly stated by the organization, the project form or the profile. assumption = your own reasonable inference that the organization has not confirmed. unknown = the organization said it does not know, has not decided, or prefers not to say (for example the budget is not determined); record it and never invent a value. open_question = an important point that is still unclear. contradiction = new input conflicts with an existing item; describe the conflict.',
            'Corrections: when the organization changes or corrects earlier information, add the new item and put the ids of the outdated items in replaces. Also use replaces to close an open_question, contradiction or assumption that the new input resolves or confirms.',
            'Priority: for business_needs and important_features use essential or optional when the organization indicated it, otherwise unspecified. Every feature must serve a stated need or be recorded as an assumption; do not add features just because similar software has them.',
            'Step 2 - coverage: assess each of the 11 areas as missing, partial, sufficient, unknown (the organization explicitly does not know or has not decided) or not_applicable.',
            'Step 3 - reply_ar: one or two short, warm sentences acknowledging what you understood from the newest input, without repeating it verbatim and without inventing anything. Use an empty string only when new_organization_input is empty.',
            'Step 4 - decide: either ask exactly ONE question (status=question) about the single most valuable missing, ambiguous or contradictory point, or finish (status=complete).',
            'Question rules: never ask for information already in requirements_state, the project form or already_asked_questions, even reworded. Usual priority: the business problem, then the current workflow and affected users, then the desired outcome, then essential needs and features, then constraints, dependencies and risks, then timeline and development budget, then operational needs (hosting, maintenance, support, recurring fees, training), then anything else. This is a guide, not a fixed sequence: adapt to what is already known and ask a follow-up when an answer reveals an important ambiguity. Explore timeline, budget and operations when relevant and always let the organization say it is not determined yet. Use simple business language without technical jargon. Set question.area to the area the question serves and why_ar to one short sentence explaining why it matters (or an empty string).',
            'Suggested answers: when a question has a natural set of typical answers use single_choice or multi_choice with 3 to 6 options specific to this organization and question; add an option with value other, label "أخرى" and allows_custom_text=true when other answers are plausible; for budget or timeline questions include an option meaning not determined yet. Use short_text or long_text with an empty options array for open questions, and an empty options array for number, date and yes_no. The organization can always answer in free text instead.',
            'Completion: set status=complete only when the problem, the current process (or an explicit unknown), the desired outcome, the main users, the essential business needs and the core features are covered after your updates (readiness.missing_core_areas must be empty) and the remaining gaps would not materially change the proposal. Do not delay with low-value questions: when readiness.ready_for_proposal is true and many questions were already asked, prefer completing. When completing, reply_ar briefly says that enough information was collected and question is null. If completion_rejected is present, you must ask about one of the listed missing areas instead of completing.',
            'mode=revision: the organization is changing an existing proposal (current_proposal is a summary of it). Understand the change against the current requirements and the proposed solution, update requirements using replaces, ask a follow-up only when the change is ambiguous or has an important unresolved impact, otherwise complete. In reply_ar mention which parts are affected (scope, timeline, cost or operations) without inventing numbers.',
            'Never recommend specific commercial products, never promise feasibility or delivery dates, and never present an assumption as a confirmed requirement. Return only JSON matching the schema.',
        ]);
    }

    private function responseSchema(): array
    {
        $areaEnum = ['type' => 'STRING', 'enum' => array_keys(DiscoveryRequirements::AREAS)];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'status' => [
                    'type' => 'STRING',
                    'enum' => ['question', 'complete'],
                ],
                'reply_ar' => ['type' => 'STRING'],
                'updates' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'area' => $areaEnum,
                            'kind' => ['type' => 'STRING', 'enum' => DiscoveryRequirements::KINDS],
                            'text' => ['type' => 'STRING'],
                            'priority' => ['type' => 'STRING', 'enum' => DiscoveryRequirements::PRIORITIES],
                            'replaces' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        ],
                        'required' => ['area', 'kind', 'text', 'priority', 'replaces'],
                    ],
                ],
                'coverage' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'area' => $areaEnum,
                            'status' => ['type' => 'STRING', 'enum' => DiscoveryRequirements::COVERAGE],
                        ],
                        'required' => ['area', 'status'],
                    ],
                ],
                'question' => [
                    'type' => 'OBJECT',
                    'nullable' => true,
                    'properties' => [
                        'id' => ['type' => 'STRING'],
                        'text_ar' => ['type' => 'STRING'],
                        'why_ar' => ['type' => 'STRING'],
                        'area' => $areaEnum,
                        'type' => [
                            'type' => 'STRING',
                            'enum' => self::QUESTION_TYPES,
                        ],
                        'options' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'label_ar' => ['type' => 'STRING'],
                                    'value' => ['type' => 'STRING'],
                                    'allows_custom_text' => ['type' => 'BOOLEAN'],
                                ],
                                'required' => ['label_ar', 'value', 'allows_custom_text'],
                            ],
                        ],
                    ],
                    'required' => ['id', 'text_ar', 'type', 'options'],
                ],
            ],
            'required' => ['status', 'reply_ar', 'updates', 'coverage'],
        ];
    }

    /**
     * يتحقق من مخرجات Gemini. سؤال غير صالح يُفشل الاستجابة كلها (لا نعرض سؤالًا مشوهًا)،
     * أما بنود المتطلبات غير الصالحة فتُهمل منفردة. الحقول الجديدة اختيارية للتوافق مع
     * الاستجابات القديمة؛ وجود updates يعني أن الحالة المنظمة يجب أن تُحدَّث.
     */
    private function normalizeStructuredOutput(mixed $output): ?array
    {
        if (! is_array($output) || array_is_list($output) || ! isset($output['status'])) {
            return null;
        }

        $extras = $this->normalizeExtras($output);

        if ($output['status'] === 'complete') {
            return ['status' => 'complete'] + $extras;
        }

        $question = $output['question'] ?? null;
        if (
            $output['status'] !== 'question'
            || ! is_array($question)
            || array_is_list($question)
            || ! $this->hasRequiredKeys($question, ['id', 'text_ar', 'type', 'options'])
            || ! is_string($question['id'])
            || trim($question['id']) === ''
            || strlen($question['id']) > 255
            || ! is_string($question['text_ar'])
            || trim($question['text_ar']) === ''
            || strlen($question['text_ar']) > 10000
            || ! is_string($question['type'])
            || ! in_array($question['type'], self::QUESTION_TYPES, true)
            || ! is_array($question['options'])
            || ! array_is_list($question['options'])
        ) {
            return null;
        }

        $choiceQuestion = in_array($question['type'], [
            'single_choice',
            'multi_choice',
        ], true);
        if (
            ($choiceQuestion && (count($question['options']) < 2 || count($question['options']) > 6))
            || (! $choiceQuestion && $question['options'] !== [])
        ) {
            return null;
        }

        $normalizedOptions = [];
        foreach ($question['options'] as $option) {
            if (
                ! is_array($option)
                || array_is_list($option)
                || ! $this->hasRequiredKeys($option, [
                    'label_ar',
                    'value',
                    'allows_custom_text',
                ])
                || ! is_string($option['label_ar'])
                || trim($option['label_ar']) === ''
                || strlen($option['label_ar']) > 255
                || ! is_string($option['value'])
                || trim($option['value']) === ''
                || strlen($option['value']) > 255
            ) {
                return null;
            }

            $allowsCustomText = $this->booleanValue($option['allows_custom_text']);
            if ($allowsCustomText === null) {
                return null;
            }

            if ($option['value'] === 'other' && ! $allowsCustomText) {
                return null;
            }

            $normalizedOptions[] = [
                'label_ar' => $option['label_ar'],
                'value' => $option['value'],
                'allows_custom_text' => $allowsCustomText,
            ];
        }

        $optionValues = array_column($normalizedOptions, 'value');
        if (count($optionValues) !== count(array_unique($optionValues))) {
            return null;
        }

        $normalizedQuestion = [
            'id' => trim($question['id']),
            'text_ar' => trim($question['text_ar']),
            'type' => $question['type'],
            'options' => $normalizedOptions,
        ];
        if (is_string($question['area'] ?? null) && isset(DiscoveryRequirements::AREAS[$question['area']])) {
            $normalizedQuestion['area'] = $question['area'];
        }
        if (is_string($question['why_ar'] ?? null) && trim($question['why_ar']) !== '') {
            $normalizedQuestion['why_ar'] = mb_substr(trim($question['why_ar']), 0, 500);
        }

        return [
            'status' => 'question',
            'question' => $normalizedQuestion,
        ] + $extras;
    }

    /**
     * @return array{reply?: string, updates?: array<int, array>, coverage?: array<string, string>}
     */
    private function normalizeExtras(array $output): array
    {
        $extras = [];

        if (is_string($output['reply_ar'] ?? null) && trim($output['reply_ar']) !== '') {
            $extras['reply'] = mb_substr(trim($output['reply_ar']), 0, 1000);
        }

        if (array_key_exists('updates', $output)) {
            $updates = [];
            foreach (is_array($output['updates']) ? $output['updates'] : [] as $update) {
                if (
                    ! is_array($update)
                    || ! isset(DiscoveryRequirements::AREAS[$update['area'] ?? null])
                    || ! in_array($update['kind'] ?? null, DiscoveryRequirements::KINDS, true)
                    || ! is_string($update['text'] ?? null)
                    || trim($update['text']) === ''
                ) {
                    continue;
                }

                $updates[] = [
                    'area' => $update['area'],
                    'kind' => $update['kind'],
                    'text' => mb_substr(trim($update['text']), 0, DiscoveryRequirements::MAX_TEXT_LENGTH),
                    'priority' => in_array($update['priority'] ?? null, DiscoveryRequirements::PRIORITIES, true)
                        ? $update['priority']
                        : 'unspecified',
                    'replaces' => array_values(array_filter(
                        array_slice(is_array($update['replaces'] ?? null) ? $update['replaces'] : [], 0, 10),
                        fn (mixed $id): bool => is_string($id) && preg_match('/^r\d{1,6}$/', $id) === 1
                    )),
                ];

                if (count($updates) === DiscoveryRequirements::MAX_UPDATES_PER_TURN) {
                    break;
                }
            }
            $extras['updates'] = $updates;
        }

        if (is_array($output['coverage'] ?? null)) {
            $coverage = [];
            foreach ($output['coverage'] as $entry) {
                if (
                    is_array($entry)
                    && isset(DiscoveryRequirements::AREAS[$entry['area'] ?? null])
                    && in_array($entry['status'] ?? null, DiscoveryRequirements::COVERAGE, true)
                ) {
                    $coverage[$entry['area']] = $entry['status'];
                }
            }
            if ($coverage !== []) {
                $extras['coverage'] = $coverage;
            }
        }

        return $extras;
    }

    private function hasRequiredKeys(array $value, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return (bool) $value;
        }

        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        return null;
    }
}
