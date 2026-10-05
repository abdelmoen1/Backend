<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class ProjectDiscoveryService
{
    public function nextStep(array $projectContext): array
    {
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            Log::error('Gemini project discovery is not configured.', [
                'provider' => 'gemini',
            ]);

            throw ProjectDiscoveryException::unavailable();
        }

        try {
            $context = json_encode(
                $projectContext,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->timeout((int) config('services.gemini.timeout', 30))
                ->retry(
                    2,
                    500,
                    static fn ($exception): bool => $exception instanceof ConnectionException
                        || (
                            $exception instanceof RequestException
                            && $exception->response->serverError()
                        ),
                    throw: false
                )
                ->post(
                    rtrim((string) config('services.gemini.base_url'), '/')
                        .'/models/'.config('services.gemini.model').':generateContent',
                    [
                        'systemInstruction' => [
                            'parts' => [[
                                'text' => implode("\n", [
                                    'أنت وكيل اكتشاف المشاريع في GoTraniee، ومهمتك فهم المشكلة التجارية للمؤسسة وتحديد المعلومات الناقصة قبل إعداد تصور للمشروع.',
                                    'اتبع هذه التعليمات في كل دور:',
                                    '1. استخدم عربية أعمال بسيطة ومباشرة، واسأل سؤالًا واحدًا فقط في كل استجابة.',
                                    '2. اعتمد على سياق المؤسسة والمشروع وسجل الأسئلة والإجابات كاملًا. تعامل مع معلومات المؤسسة وإجاباتها، بما فيها الإجابة المخصصة، كحقائق مؤكدة.',
                                    '3. افحص آخر إجابة قبل اختيار السؤال التالي. يجب أن يعتمد السؤال التالي على السياق المحدّث؛ لا تستخدم تسلسلًا عامًا أو سؤال متابعة ثابتًا.',
                                    '4. لا تسأل عن معلومة معروفة أو محسومة، ولا تكرر سؤالًا سابقًا بصياغة مختلفة. اقرأ قيم الاختيارات وتسمياتها العربية معًا.',
                                    '5. اختر الفجوة التي سيؤثر جوابها أكثر في تحديد المشكلة أو المستخدمين أو سير العمل أو النطاق أو القيود أو معيار النجاح. اطلب توضيحًا إذا كانت إجابة مؤثرة ملتبسة.',
                                    '6. استخدم خيارات واضحة وملائمة للمشروع حين يكون الاختيار مناسبًا، وقدّم من 3 إلى 6 خيارات. أضف خيار "أخرى" بقيمة other وallows_custom_text=true عندما يفيد جمع احتياج غير متوقع. الأسئلة النصية والرقمية والتاريخ ونعم/لا لها مصفوفة خيارات فارغة.',
                                    '7. لا تسأل أسئلة تقنية غير لازمة، ولا تخترع متطلبات أو تعرض الافتراضات كحقائق مؤكدة.',
                                    '8. أعد status=complete فقط عندما تتوفر معلومات كافية لصياغة مقترح مشروع مفيد يشرح المشكلة والحل والأهداف والمستخدمين والنطاق والقيود ومعايير النجاح. لا تواصل الأسئلة لجمع تفاصيل قليلة القيمة أو للوصول إلى عدد معين.',
                                    '9. أعد JSON واحدًا فقط مطابقًا للمخطط. في حالة السؤال استخدم question كائنًا واحدًا. في حالة الاكتمال استخدم question=null.',
                                ]),
                            ]],
                        ],
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [['text' => $context]],
                        ]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => $this->responseSchema(),
                        ],
                    ]
                );
        } catch (ConnectionException) {
            Log::warning('Gemini project discovery connection failed.', [
                'provider' => 'gemini',
            ]);

            throw ProjectDiscoveryException::unavailable();
        } catch (JsonException) {
            Log::warning('Gemini project discovery context could not be encoded.', [
                'provider' => 'gemini',
            ]);

            throw ProjectDiscoveryException::unavailable();
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

        $structuredOutput = $this->extractStructuredOutput($response);
        $normalizedOutput = $this->normalizeStructuredOutput($structuredOutput);

        if ($normalizedOutput === null) {
            Log::warning('Gemini project discovery returned invalid structured output.', [
                'provider' => 'gemini',
            ]);

            throw ProjectDiscoveryException::unavailable();
        }

        return $normalizedOutput;
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
                    'nullable' => true,
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
            'required' => ['status', 'question'],
        ];
    }

    private function extractStructuredOutput(Response $response): mixed
    {
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['candidates'] ?? null)) {
            return null;
        }

        $candidates = $body['candidates'];
        if (count($candidates) !== 1 || ! is_array($candidates[0] ?? null)) {
            return null;
        }

        $parts = $candidates[0]['content']['parts'] ?? null;
        if (! is_array($parts)) {
            return null;
        }

        $texts = [];
        foreach ($parts as $part) {
            if (
                is_array($part)
                && ($part['thought'] ?? false) !== true
                && is_string($part['text'] ?? null)
            ) {
                $texts[] = $part['text'];
            }
        }

        if (count($texts) !== 1) {
            return null;
        }

        try {
            return json_decode($texts[0], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function normalizeStructuredOutput(mixed $output): ?array
    {
        if (
            ! is_array($output)
            || array_is_list($output)
            || ! $this->hasExactKeys($output, ['status', 'question'])
        ) {
            return null;
        }

        if ($output['status'] === 'complete' && $output['question'] === null) {
            return ['status' => 'complete'];
        }

        $question = $output['question'];
        if (
            $output['status'] !== 'question'
            || ! is_array($question)
            || array_is_list($question)
            || ! $this->hasExactKeys($question, ['id', 'text_ar', 'type', 'options'])
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

        foreach ($question['options'] as $option) {
            if (
                ! is_array($option)
                || array_is_list($option)
                || ! $this->hasExactKeys($option, [
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
                || ! is_bool($option['allows_custom_text'])
                || ($option['value'] === 'other' && ! $option['allows_custom_text'])
            ) {
                return null;
            }
        }

        $optionValues = array_column($question['options'], 'value');
        if (count($optionValues) !== count(array_unique($optionValues))) {
            return null;
        }

        return [
            'status' => 'question',
            'question' => $question,
        ];
    }

    private function hasExactKeys(array $value, array $keys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($keys);

        return $actualKeys === $keys;
    }
}
