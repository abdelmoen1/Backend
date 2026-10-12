<?php

namespace Tests\Unit;

use App\Services\AI\GeminiFailure;
use App\Services\AI\ProjectDiscoveryException;
use App\Services\AI\ProjectDiscoveryService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectDiscoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.timeout' => 30,
            'services.gemini.max_retries' => 2,
            'services.gemini.retry_delay_ms' => 0,
        ]);
    }

    public function test_it_returns_a_validated_question_from_gemini(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response(
                $this->responseWithText([
                    'status' => 'question',
                    'question' => [
                        'id' => 'appointment_priority',
                        'text_ar' => 'ما الأولوية الأهم؟',
                        'type' => 'single_choice',
                        'options' => [
                            [
                                'label_ar' => 'تقليل التعارض',
                                'value' => 'avoid_conflicts',
                                'allows_custom_text' => false,
                            ],
                            [
                                'label_ar' => 'أخرى',
                                'value' => 'other',
                                'allows_custom_text' => true,
                            ],
                        ],
                    ],
                ]),
                200
            ),
        ]);

        $result = app(ProjectDiscoveryService::class)->nextStep([
            'organization_type' => 'عيادة',
        ]);

        $this->assertSame('question', $result['status']);
        $this->assertSame('appointment_priority', $result['question']['id']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
                && $request->hasHeader('x-goog-api-key')
                && $request['systemInstruction']['parts'][0]['text'] !== ''
                && $request['contents'][0]['parts'][0]['text'] !== ''
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && $request['generationConfig']['responseSchema']['properties']['question']['nullable'] === true;
        });
    }

    public function test_it_returns_the_completion_contract_without_a_question_field(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response(
                $this->responseWithText([
                    'status' => 'complete',
                    'question' => null,
                ]),
                200
            ),
        ]);

        $result = app(ProjectDiscoveryService::class)->nextStep([]);

        $this->assertSame(['status' => 'complete'], $result);
    }

    public function test_it_accepts_each_supported_question_type(): void
    {
        $questionTypes = [
            ['single_choice', [
                ['label_ar' => 'أ', 'value' => 'a', 'allows_custom_text' => false],
                ['label_ar' => 'ب', 'value' => 'b', 'allows_custom_text' => false],
            ]],
            ['multi_choice', [
                ['label_ar' => 'أ', 'value' => 'a', 'allows_custom_text' => false],
                ['label_ar' => 'ب', 'value' => 'b', 'allows_custom_text' => false],
            ]],
            ['short_text', []],
            ['long_text', []],
            ['number', []],
            ['date', []],
            ['yes_no', []],
        ];
        $sequence = Http::sequence();
        foreach ($questionTypes as $index => [$type, $options]) {
            $sequence->push($this->responseWithText([
                'status' => 'question',
                'question' => [
                    'id' => 'q'.$index,
                    'text_ar' => 'سؤال مناسب',
                    'type' => $type,
                    'options' => $options,
                ],
            ]));
        }
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => $sequence,
        ]);

        foreach ($questionTypes as [$type]) {
            $result = app(ProjectDiscoveryService::class)->nextStep([]);
            $this->assertSame($type, $result['question']['type']);
        }
    }

    public function test_it_does_not_expose_gemini_provider_errors(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response(
                ['error' => [
                    'status' => 'UNAUTHENTICATED',
                    'message' => 'provider detail that must stay internal',
                ]],
                401
            ),
        ]);

        config(['services.gemini.api_key' => 'AIza1234567890123456789012345678901234']);
        try {
            app(ProjectDiscoveryService::class)->nextStep([]);
            $this->fail('Expected the provider failure to be handled.');
        } catch (ProjectDiscoveryException $exception) {
            $this->assertStringNotContainsString(
                'provider detail',
                $exception->getMessage()
            );
            $this->assertSame(401, $exception->providerDiagnostic['http_status']);
            $this->assertSame('UNAUTHENTICATED', $exception->providerDiagnostic['provider_status']);
            $this->assertStringNotContainsString(
                'AIza1234567890123456789012345678901234',
                json_encode($exception->providerDiagnostic)
            );
        }
    }

    public function test_provider_quota_diagnostic_is_available_to_smoke_command_only(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response([
                'error' => [
                    'code' => 429,
                    'status' => 'RESOURCE_EXHAUSTED',
                    'message' => 'Quota exceeded for model; key=secret-value',
                ],
            ], 429),
        ]);

        try {
            app(ProjectDiscoveryService::class)->nextStep([]);
            $this->fail('Expected the provider quota failure to be handled.');
        } catch (ProjectDiscoveryException $exception) {
            $this->assertSame(429, $exception->providerDiagnostic['http_status']);
            $this->assertSame('RESOURCE_EXHAUSTED', $exception->providerDiagnostic['provider_status']);
            $this->assertStringContainsString('Quota exceeded', $exception->providerDiagnostic['provider_message']);
            $this->assertStringNotContainsString('secret-value', $exception->providerDiagnostic['provider_message']);
            $this->assertStringNotContainsString('RESOURCE_EXHAUSTED', $exception->getMessage());
        }
    }

    public function test_it_retries_transient_gemini_server_errors(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::sequence()
                ->push(['error' => ['message' => 'temporarily unavailable']], 503)
                ->push($this->responseWithText([
                    'status' => 'complete',
                    'question' => null,
                ]), 200),
        ]);

        $result = app(ProjectDiscoveryService::class)->nextStep([]);

        $this->assertSame(['status' => 'complete'], $result);
        Http::assertSentCount(2);
    }

    public function test_it_handles_connection_failures_without_exposing_details(): void
    {
        $cases = [
            ['connection timeout detail at internal-host', 'ai_timeout', GeminiFailure::TIMEOUT, 1],
            ['cURL error 7: Failed to connect to internal-host', 'ai_temporarily_unavailable', GeminiFailure::CONNECTION_FAILED, 2],
        ];

        // Http::fake يضيف المحاكيات ولا يستبدلها، لذا محاكاة واحدة تقرأ الحالة الحالية
        $message = '';
        $sent = 0;
        Http::fake(function () use (&$message, &$sent): never {
            $sent++;
            throw new ConnectionException($message);
        });

        foreach ($cases as [$message, $code, $reason, $attempts]) {
            $sent = 0;

            try {
                app(ProjectDiscoveryService::class)->nextStep([]);
                $this->fail('Expected a connection failure to be handled.');
            } catch (ProjectDiscoveryException $exception) {
                $this->assertSame($code, $exception->errorCode());
                $this->assertSame($reason, $exception->providerDiagnostic['reason']);
                $this->assertTrue($exception->retryable());
                $this->assertStringNotContainsString('internal-host', $exception->getMessage());
                $this->assertStringNotContainsString('internal-host', json_encode($exception->providerDiagnostic));
            }
            // انتهاء المهلة لا يُعاد داخل الطلب؛ فشل الاتصال السريع يُعاد ضمن الحد
            $this->assertSame($attempts, $sent);
        }
    }

    public function test_provider_failures_are_classified_with_safe_public_codes(): void
    {
        $quotaFailure = fn (string $quotaId, string $delay): array => ['error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'You exceeded your current quota.',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $delay],
            ],
        ]];
        $cases = [
            'overloaded' => [['error' => ['status' => 'UNAVAILABLE', 'message' => 'The model is overloaded.']], 503, 'ai_temporarily_unavailable', GeminiFailure::UPSTREAM_UNAVAILABLE, true, 5],
            'per minute limit' => [$quotaFailure('GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '37s'), 429, 'ai_rate_limited', GeminiFailure::RATE_LIMITED, true, 37],
            'daily quota' => [$quotaFailure('GenerateRequestsPerDayPerProjectPerModel-FreeTier', '12s'), 429, 'ai_quota_exhausted', GeminiFailure::QUOTA_EXHAUSTED, false, null],
            'invalid key' => [['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid.', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400, 'ai_configuration_error', GeminiFailure::INVALID_CREDENTIALS, false, null],
            'invalid payload' => [['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received.']], 400, 'ai_configuration_error', GeminiFailure::INVALID_REQUEST, false, null],
            'unsupported location' => [['error' => ['status' => 'FAILED_PRECONDITION', 'message' => 'User location is not supported for the API use.']], 400, 'ai_configuration_error', GeminiFailure::PRECONDITION_FAILED, false, null],
            'permission' => [['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Permission denied.']], 403, 'ai_configuration_error', GeminiFailure::PERMISSION_DENIED, false, null],
            'unknown model' => [['error' => ['status' => 'NOT_FOUND', 'message' => 'models/x is not found for API version v1beta.']], 404, 'ai_configuration_error', GeminiFailure::MODEL_NOT_FOUND, false, null],
        ];

        $current = [];
        Http::fake(function () use (&$current) {
            return Http::response($current[0], $current[1]);
        });

        foreach ($cases as $name => [$body, $status, $code, $reason, $retryable, $retryAfter]) {
            $current = [$body, $status];

            try {
                app(ProjectDiscoveryService::class)->nextStep([]);
                $this->fail("Expected the {$name} failure to be handled.");
            } catch (ProjectDiscoveryException $exception) {
                $this->assertSame($code, $exception->errorCode(), $name);
                $this->assertSame($reason, $exception->providerDiagnostic['reason'], $name);
                $this->assertSame($status, $exception->providerDiagnostic['http_status'], $name);
                $this->assertSame($retryable, $exception->retryable(), $name);
                $this->assertSame($retryAfter, $exception->retryAfter(), $name);
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $exception->getMessage(), $name);
            }
        }
    }

    public function test_it_rejects_malformed_and_invalid_structured_output(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::sequence()
                ->push($this->responseWithText('not json'), 200)
                ->push($this->responseWithText([
                    'status' => 'question',
                    'question' => [
                        'id' => '',
                        'text_ar' => 'سؤال',
                        'type' => 'single_choice',
                        'options' => [
                            [
                                'label_ar' => 'اختيار',
                                'value' => 'choice',
                                'allows_custom_text' => false,
                            ],
                            [
                                'label_ar' => 'بديل',
                                'value' => 'alternative',
                                'allows_custom_text' => false,
                            ],
                        ],
                    ],
                ]), 200),
        ]);

        foreach (['invalid_json', 'schema_validation'] as $parseError) {
            try {
                app(ProjectDiscoveryService::class)->nextStep([]);
                $this->fail('Expected invalid structured output to be rejected.');
            } catch (ProjectDiscoveryException $exception) {
                $this->assertSame('ai_invalid_response', $exception->errorCode());
                $this->assertSame(GeminiFailure::INVALID_OUTPUT, $exception->providerDiagnostic['reason']);
                $this->assertSame($parseError, $exception->providerDiagnostic['parse_error']);
                $this->assertSame('STOP', $exception->providerDiagnostic['finish_reason']);
                $this->assertTrue($exception->retryable());
                $this->assertSame(
                    'تعذر إنشاء سؤال جديد للاكتشاف حاليًا. إجاباتك محفوظة، ويمكنك المحاولة مرة أخرى.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_it_fails_safely_when_the_api_key_is_unconfigured(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        try {
            app(ProjectDiscoveryService::class)->nextStep([]);
            $this->fail('Expected missing configuration to be rejected.');
        } catch (ProjectDiscoveryException $exception) {
            $this->assertSame('ai_configuration_error', $exception->errorCode());
            $this->assertSame(GeminiFailure::NOT_CONFIGURED, $exception->providerDiagnostic['reason']);
            $this->assertFalse($exception->retryable());
            $this->assertNull($exception->retryAfter());
        }

        Http::assertNothingSent();
    }

    private function responseWithText(mixed $structuredOutput): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => is_string($structuredOutput)
                            ? $structuredOutput
                            : json_encode($structuredOutput, JSON_UNESCAPED_UNICODE),
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
        ];
    }
}
