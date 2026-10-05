<?php

namespace Tests\Unit;

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
        Http::fake(function (): never {
            throw new ConnectionException('connection timeout detail');
        });

        try {
            app(ProjectDiscoveryService::class)->nextStep([]);
            $this->fail('Expected a connection failure to be handled.');
        } catch (ProjectDiscoveryException $exception) {
            $this->assertSame(
                'تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا.',
                $exception->getMessage()
            );
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

        foreach (range(1, 2) as $_) {
            try {
                app(ProjectDiscoveryService::class)->nextStep([]);
                $this->fail('Expected invalid structured output to be rejected.');
            } catch (ProjectDiscoveryException $exception) {
                $this->assertSame(
                    'تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا.',
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
            $this->assertSame(
                'تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا.',
                $exception->getMessage()
            );
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
