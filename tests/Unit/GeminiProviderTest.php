<?php

namespace Tests\Unit;

use App\Services\AI\AIProviderConfigurationException;
use App\Services\AI\GeminiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_it_rejects_missing_credentials_without_echoing_them(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(AIProviderConfigurationException::class);
        app(GeminiProvider::class)->generateResponse(['contents' => []]);
    }
}
