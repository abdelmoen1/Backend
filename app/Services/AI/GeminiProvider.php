<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class GeminiProvider implements AIProvider
{
    /**
     * أطول انتظار بين محاولتين داخل نفس الطلب. انتظار أطول (مثل حد الطلبات بالدقيقة) يُعاد
     * للمستخدم كمدة انتظار بدل حجز الطلب ومهلة البوابة.
     */
    private const MAX_RETRY_DELAY_MS = 10000;

    /**
     * إعداد التفكير المشترك لكل ميزات Gemini (اكتشاف المشاريع وتقييم المهارات): GEMINI_THINKING_BUDGET إن ضُبط،
     * وإلا صفر للنماذج المفكرة أو الكبيرة المعروفة حتى لا تبطؤ، ولا شيء لغيرها.
     */
    public static function thinkingConfig(): array
    {
        $configured = config('services.gemini.thinking_budget');
        if ($configured !== null && $configured !== '') {
            return ['thinkingBudget' => (int) $configured];
        }

        $model = (string) config('services.gemini.model');
        if (preg_match('/(?:gemini-3\.5|thinking|pro)/i', $model) === 1) {
            return ['thinkingBudget' => 0];
        }

        return [];
    }

    public function generateResponse(array $payload): Response
    {
        $apiKey = config('services.gemini.api_key');
        $apiKey = is_string($apiKey) ? trim($apiKey) : '';
        if ($apiKey === '') {
            throw AIProviderConfigurationException::notConfigured();
        }

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $model = (string) config('services.gemini.model');
        // GEMINI_MAX_RETRIES هو العدد الكلي للمحاولات بما فيها الأولى
        $maxAttempts = max(1, (int) config('services.gemini.max_retries', 3));
        $baseDelayMs = max(0, (int) config('services.gemini.retry_delay_ms', 1000));
        $timeout = max(1, (int) config('services.gemini.timeout', 60));

        for ($attempt = 1; ; $attempt++) {
            // عدد المحاولات متاح لسطر السجل النهائي في نفس الطلب دون أن يظهر في كل سجل
            Context::addHidden('gemini_attempts', $attempt);

            try {
                $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post($baseUrl.'/models/'.$model.':generateContent', $payload);
            } catch (ConnectionException $exception) {
                $delay = $this->retryDelay(GeminiFailure::fromConnection($exception), $attempt, $maxAttempts, $baseDelayMs);
                if ($delay === null) {
                    throw $exception;
                }
                Sleep::usleep($delay * 1000);

                continue;
            }

            if (! $response->failed()) {
                return $response;
            }

            $delay = $this->retryDelay(GeminiFailure::fromResponse($response, $apiKey), $attempt, $maxAttempts, $baseDelayMs);
            if ($delay === null) {
                return $response;
            }
            Sleep::usleep($delay * 1000);
        }
    }

    /**
     * انتظار أُسّي مع عشوائية محدودة للأخطاء المؤقتة فقط، ويحترم مدة الانتظار التي يحددها Gemini.
     * null يعني التوقف وإرجاع الفشل للمستدعي.
     */
    private function retryDelay(GeminiFailure $failure, int $attempt, int $maxAttempts, int $baseDelayMs): ?int
    {
        $delay = null;
        if ($attempt < $maxAttempts && $failure->shouldRetryNow()) {
            $backoff = (int) min(self::MAX_RETRY_DELAY_MS, $baseDelayMs * 2 ** ($attempt - 1));
            $delay = $backoff > 0 ? random_int(intdiv($backoff, 2), $backoff) : 0;

            if ($failure->retryAfterSeconds !== null) {
                $hinted = $failure->retryAfterSeconds * 1000;
                $delay = $hinted > self::MAX_RETRY_DELAY_MS ? null : max($delay, $hinted);
            }
        }

        Log::warning('Gemini request attempt failed.', [
            'provider' => 'gemini',
            'model' => (string) config('services.gemini.model'),
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'will_retry' => $delay !== null,
            'retry_in_ms' => $delay,
            ...$failure->diagnostic(),
        ]);

        return $delay;
    }
}
