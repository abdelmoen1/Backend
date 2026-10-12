<?php

namespace App\Services\Assessments;

use App\Contracts\AIProvider;
use App\Services\AI\AIProviderConfigurationException;
use App\Services\AI\GeminiFailure;
use App\Services\AI\GeminiStructuredOutput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * طلب JSON منظم من Gemini (المزود الوحيد) للتوليد والتصحيح. فشل النقل أو الإعدادات يُرمى كخطأ عام
 * آمن العرض؛ الرد غير الصالح يعود null ليقرر المستدعي إعادة المحاولة. لا يُسجَّل محتوى الأسئلة أو
 * الإجابات في السجلات.
 */
class AssessmentAiClient
{
    public function __construct(private readonly AIProvider $provider) {}

    public function json(array $payload, string $operation): mixed
    {
        try {
            $response = $this->provider->generateResponse($payload);
        } catch (AIProviderConfigurationException) {
            Log::error('Gemini assessment request is not configured.', ['provider' => 'gemini', 'operation' => $operation]);

            throw AssessmentException::ai('ai_configuration_error', false, $operation);
        } catch (ConnectionException $exception) {
            $failure = GeminiFailure::fromConnection($exception);
            Log::warning('Gemini assessment connection failed.', ['operation' => $operation, ...$failure->diagnostic()]);

            throw AssessmentException::ai($failure->publicCode(), $failure->isTransient(), $operation);
        }

        if ($response->failed()) {
            $failure = GeminiFailure::fromResponse($response, (string) config('services.gemini.api_key'));
            Log::warning('Gemini assessment request failed.', ['operation' => $operation, ...$failure->diagnostic()]);

            throw AssessmentException::ai($failure->publicCode(), $failure->isTransient(), $operation);
        }

        $extracted = GeminiStructuredOutput::extract($response);
        if ($extracted['data'] === null) {
            Log::warning('Gemini assessment returned unreadable output.', ['operation' => $operation, ...$extracted['diagnostic']]);
        }

        return $extracted['data'];
    }
}
