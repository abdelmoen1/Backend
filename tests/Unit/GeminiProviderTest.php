<?php

namespace Tests\Unit;

use App\Services\AI\AIProviderConfigurationException;
use App\Services\AI\GeminiProvider;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.model' => 'gemini-test-model',
            'services.gemini.timeout' => 7,
            'services.gemini.max_retries' => 2,
            'services.gemini.retry_delay_ms' => 0,
        ]);
    }

    public function test_it_sends_a_structured_request_to_the_configured_gemini_model(): void
    {
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => '{"project":"clinic"}']],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => ['type' => 'OBJECT'],
            ],
        ];
        Http::fake(['*' => Http::response(['candidates' => []])]);

        $response = app(GeminiProvider::class)->generateResponse($payload);

        $this->assertTrue($response->successful());
        Http::assertSent(function (Request $request) use ($payload): bool {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && $request->data() === $payload;
        });
    }

    public function test_it_returns_provider_http_failures_for_safe_domain_handling(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'private provider detail']], 503)
                ->push(['error' => ['message' => 'private provider detail']], 503),
        ]);

        $response = app(GeminiProvider::class)->generateResponse(['contents' => []]);

        $this->assertSame(503, $response->status());
        Http::assertSentCount(2);
    }

    public function test_it_retries_a_transient_server_failure_once(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'temporary failure']], 503)
                ->push(['candidates' => []], 200),
        ]);

        $response = app(GeminiProvider::class)->generateResponse(['contents' => []]);

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    public function test_it_propagates_connection_failure_for_the_domain_service_to_sanitize(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('private connection detail');
        });

        try {
            app(GeminiProvider::class)->generateResponse(['contents' => []]);
            $this->fail('A connection failure must be propagated to the caller.');
        } catch (ConnectionException $exception) {
            $this->assertSame('private connection detail', $exception->getMessage());
        }
    }

    public function test_transient_failures_use_bounded_exponential_backoff(): void
    {
        config(['services.gemini.max_retries' => 3, 'services.gemini.retry_delay_ms' => 1000]);
        Sleep::fake();
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
                ->push(['error' => ['status' => 'INTERNAL']], 500)
                ->push(['candidates' => []], 200),
        ]);

        $response = app(GeminiProvider::class)->generateResponse(['contents' => []]);

        $this->assertTrue($response->successful());
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        Sleep::assertSlept(fn (CarbonInterval $delay): bool => $delay->totalMilliseconds >= 500 && $delay->totalMilliseconds <= 1000);
        Sleep::assertSlept(fn (CarbonInterval $delay): bool => $delay->totalMilliseconds >= 1000 && $delay->totalMilliseconds <= 2000);
    }

    public function test_it_stops_at_the_attempt_limit(): void
    {
        config(['services.gemini.max_retries' => 3]);
        Http::fake(['*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);

        $response = app(GeminiProvider::class)->generateResponse(['contents' => []]);

        $this->assertSame(503, $response->status());
        Http::assertSentCount(3);
    }

    public function test_a_short_rate_limit_waits_for_the_hinted_delay_and_a_long_one_is_returned(): void
    {
        config(['services.gemini.max_retries' => 3]);
        Sleep::fake();
        $rateLimited = fn (string $delay): array => ['error' => [
            'status' => 'RESOURCE_EXHAUSTED',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $delay]],
        ]];
        Http::fake([
            '*' => Http::sequence()
                ->push($rateLimited('2s'), 429)
                ->push(['candidates' => []], 200)
                ->push($rateLimited('45s'), 429),
        ]);

        $this->assertTrue(app(GeminiProvider::class)->generateResponse(['contents' => []])->successful());
        Sleep::assertSlept(fn (CarbonInterval $delay): bool => $delay->totalMilliseconds === 2000.0);

        // انتظار أطول من الحد يُعاد للمستدعي فورًا بدل حجز الطلب
        $this->assertSame(429, app(GeminiProvider::class)->generateResponse(['contents' => []])->status());
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
    }

    public function test_quota_configuration_and_request_errors_are_not_retried(): void
    {
        config(['services.gemini.max_retries' => 3]);
        $responses = [
            [['error' => ['status' => 'RESOURCE_EXHAUSTED', 'details' => [['violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]], ['retryDelay' => '3s']]]], 429],
            [['error' => ['status' => 'INVALID_ARGUMENT']], 400],
            [['error' => ['status' => 'UNAUTHENTICATED']], 401],
            [['error' => ['status' => 'PERMISSION_DENIED']], 403],
            [['error' => ['status' => 'NOT_FOUND']], 404],
        ];

        // Http::fake يضيف المحاكيات ولا يستبدلها، لذا محاكاة واحدة تقرأ الحالة الحالية
        $current = [];
        $sent = 0;
        Http::fake(function () use (&$current, &$sent) {
            $sent++;

            return Http::response($current[0], $current[1]);
        });

        foreach ($responses as $current) {
            $sent = 0;

            $this->assertSame($current[1], app(GeminiProvider::class)->generateResponse(['contents' => []])->status());
            $this->assertSame(1, $sent, "HTTP {$current[1]} must not be retried.");
        }
    }

    public function test_timeouts_are_not_retried_but_quick_connection_failures_are(): void
    {
        config(['services.gemini.max_retries' => 3]);
        $failure = 'cURL error 28: Operation timed out after 7001 milliseconds';
        $sent = 0;
        Http::fake(function () use (&$failure, &$sent) {
            $sent++;
            if ($failure !== null) {
                $message = $failure;
                $failure = null;
                throw new ConnectionException($message);
            }

            return Http::response(['candidates' => []]);
        });

        try {
            app(GeminiProvider::class)->generateResponse(['contents' => []]);
            $this->fail('A timeout must be propagated.');
        } catch (ConnectionException) {
            $this->assertSame(1, $sent);
        }

        $failure = 'cURL error 7: Failed to connect';
        $sent = 0;

        $this->assertTrue(app(GeminiProvider::class)->generateResponse(['contents' => []])->successful());
        $this->assertSame(2, $sent);
    }

    public function test_it_rejects_missing_credentials_without_echoing_them(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(AIProviderConfigurationException::class);
        app(GeminiProvider::class)->generateResponse(['contents' => []]);
    }
}
