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
        $apiKey = is_string($apiKey) ? trim($apiKey) : '';
        if ($apiKey === '') {
            throw AIProviderConfigurationException::notConfigured();
        }

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $model = (string) config('services.gemini.model');
        $tries = max(1, (int) config('services.gemini.max_retries', 2));
        $retryDelayMs = max(0, (int) config('services.gemini.retry_delay_ms', 1000));

        return Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->acceptJson()
            ->timeout((int) config('services.gemini.timeout', 60))
            ->retry(
                $tries,
                $retryDelayMs,
                static function ($exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException || ! $exception->response) {
                        return false;
                    }

                    $status = $exception->response->status();

                    return $exception->response->serverError()
                        || $status === 408
                        || $status === 429;
                },
                throw: false
            )
            ->post(
                $baseUrl.'/models/'.$model.':generateContent',
                $payload
            );
    }
}
