<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AIProvider
{
    public function generateResponse(array $payload): Response
    {
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw AIProviderConfigurationException::notConfigured();
        }

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $model = (string) config('services.gemini.model');

        return Http::withHeaders(['x-goog-api-key' => $apiKey])
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
                $baseUrl.'/models/'.$model.':generateContent',
                $payload
            );
    }
}
