<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use JsonException;

class ProjectDiscoveryService
{
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
                'maxOutputTokens' => 2048,
            ];
            $thinkingConfig = $this->thinkingConfig();
            if ($thinkingConfig !== []) {
                $generationConfig['thinkingConfig'] = $thinkingConfig;
            }

            $response = $this->provider->generateResponse([
                'systemInstruction' => [
                    'parts' => [[
                        'text' => implode("\n", [
                            'You are the GoTraniee AI Requirement Intake Agent for organizations.',
                            'Your job is to collect enough structured requirements to later create a Project Proposal.',
                            'Ask one relevant question at a time. Use simple Arabic business language.',
                            'Use previous answers as context. Do not repeat a question that has already been asked or answered, even with different wording.',
                            'Treat organization profile data, project context, custom answers, and "Other" text as confirmed user facts. Never invent requirements. Never promote your own assumptions into confirmed_requirements.',
                            'Inspect latest_answer and the full ordered history before choosing the next question. The next question must depend on that updated context; do not follow a generic questionnaire sequence.',
                            'Ask a clarification when an important answer is incomplete or ambiguous.',
                            'Do not recommend existing commercial software. Do not start designing a technical solution yet.',
                            'Prefer 3 to 6 clear project-specific options when a choice question fits. Add an "أخرى" option with value other and allows_custom_text=true when an unexpected need is possible. Text, number, date, and yes/no questions must use an empty options array.',
                            'Do not ask unnecessary technical questions, and do not re-ask information already present in project context or confirmed answers.',
                            'Set status=complete only when there is enough information for a useful project proposal covering the problem, current process, users, goals, scope, constraints, and success criteria. Do not pad with low-value questions.',
                            'Return JSON that matches the schema. For a question, set status=question and provide one question object. For completion, set status=complete and omit question or set it to null.',
                        ]),
                    ]],
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

    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'status' => [
                    'type' => 'STRING',
                    'enum' => ['question', 'complete'],
                ],
                'question' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'id' => ['type' => 'STRING'],
                        'text_ar' => ['type' => 'STRING'],
                        'type' => [
                            'type' => 'STRING',
                            'enum' => [
                                'single_choice',
                                'multi_choice',
                                'short_text',
                                'long_text',
                                'number',
                                'date',
                                'yes_no',
                            ],
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
            'required' => ['status'],
        ];
    }

    private function normalizeStructuredOutput(mixed $output): ?array
    {
        if (! is_array($output) || array_is_list($output) || ! isset($output['status'])) {
            return null;
        }

        if ($output['status'] === 'complete') {
            return ['status' => 'complete'];
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
            || ! in_array($question['type'], [
                'single_choice',
                'multi_choice',
                'short_text',
                'long_text',
                'number',
                'date',
                'yes_no',
            ], true)
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

        return [
            'status' => 'question',
            'question' => [
                'id' => trim($question['id']),
                'text_ar' => trim($question['text_ar']),
                'type' => $question['type'],
                'options' => $normalizedOptions,
            ],
        ];
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
