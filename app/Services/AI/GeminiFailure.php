<?php

namespace App\Services\AI;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * تصنيف موحد لفشل طلب Gemini: السبب، وهل تفيد إعادة المحاولة، ومدة الانتظار المقترحة.
 * رسالة المزود تُنقّى من المفاتيح قبل حفظها، ولا يُحتفظ بأي ترويسة أو محتوى للطلب.
 */
final class GeminiFailure
{
    public const NOT_CONFIGURED = 'not_configured';

    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const PERMISSION_DENIED = 'permission_denied';

    public const MODEL_NOT_FOUND = 'model_not_found';

    public const INVALID_REQUEST = 'invalid_request';

    public const PRECONDITION_FAILED = 'precondition_failed';

    public const RATE_LIMITED = 'rate_limited';

    public const QUOTA_EXHAUSTED = 'quota_exhausted';

    public const UPSTREAM_UNAVAILABLE = 'upstream_unavailable';

    public const TIMEOUT = 'timeout';

    public const CONNECTION_FAILED = 'connection_failed';

    public const INVALID_OUTPUT = 'invalid_output';

    public const REPEATED_QUESTION = 'repeated_question';

    public const CONTEXT_ENCODING = 'context_encoding';

    /** الرمز العام الذي يصل إلى المتصفح؛ التفاصيل الدقيقة تبقى في سجلات الخادم. */
    private const PUBLIC_CODES = [
        self::UPSTREAM_UNAVAILABLE => 'ai_temporarily_unavailable',
        self::CONNECTION_FAILED => 'ai_temporarily_unavailable',
        self::TIMEOUT => 'ai_timeout',
        self::RATE_LIMITED => 'ai_rate_limited',
        self::QUOTA_EXHAUSTED => 'ai_quota_exhausted',
        self::INVALID_OUTPUT => 'ai_invalid_response',
        self::REPEATED_QUESTION => 'ai_invalid_response',
        self::NOT_CONFIGURED => 'ai_configuration_error',
        self::INVALID_CREDENTIALS => 'ai_configuration_error',
        self::PERMISSION_DENIED => 'ai_configuration_error',
        self::MODEL_NOT_FOUND => 'ai_configuration_error',
        self::INVALID_REQUEST => 'ai_configuration_error',
        self::PRECONDITION_FAILED => 'ai_configuration_error',
        self::CONTEXT_ENCODING => 'ai_configuration_error',
    ];

    /** أسباب قد تنجح إعادة المحاولة فيها لاحقًا دون تدخل في الإعدادات. */
    private const TRANSIENT = [
        self::UPSTREAM_UNAVAILABLE,
        self::CONNECTION_FAILED,
        self::TIMEOUT,
        self::RATE_LIMITED,
        self::INVALID_OUTPUT,
        self::REPEATED_QUESTION,
    ];

    private const MAX_RETRY_AFTER_SECONDS = 86400;

    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $providerMessage = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $details = [],
    ) {}

    public static function fromResponse(Response $response, string $apiKey = ''): self
    {
        $status = $response->status();
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $providerStatus = is_string($error['status'] ?? null) ? mb_substr(trim($error['status']), 0, 80) : null;
        $message = is_string($error['message'] ?? null) ? self::sanitize($error['message'], $apiKey) : null;
        $details = array_values(array_filter(
            is_array($error['details'] ?? null) ? $error['details'] : [],
            'is_array'
        ));

        $reason = match (true) {
            $status === 401 || self::hasErrorReason($details, 'API_KEY_INVALID') => self::INVALID_CREDENTIALS,
            $status === 403 => self::PERMISSION_DENIED,
            $status === 404 => self::MODEL_NOT_FOUND,
            $status === 429 => self::isLongTermQuota($details, $message) ? self::QUOTA_EXHAUSTED : self::RATE_LIMITED,
            $status === 400 && $providerStatus === 'FAILED_PRECONDITION' => self::PRECONDITION_FAILED,
            $status === 408 || $status >= 500 => self::UPSTREAM_UNAVAILABLE,
            default => self::INVALID_REQUEST,
        };

        return new self($reason, $status, $providerStatus, $message, self::retryAfter($response, $details));
    }

    public static function fromConnection(ConnectionException $exception): self
    {
        $previous = $exception->getPrevious();
        $errno = $previous instanceof ConnectException
            ? ($previous->getHandlerContext()['errno'] ?? null)
            : null;
        $timedOut = $errno === 28
            || preg_match('/cURL error 28|timed? ?out|timeout/i', $exception->getMessage()) === 1;

        // رسالة الاتصال قد تتضمن عناوين داخلية؛ نكتفي برقم خطأ cURL
        return new self(
            $timedOut ? self::TIMEOUT : self::CONNECTION_FAILED,
            details: is_int($errno) ? ['curl_errno' => $errno] : [],
        );
    }

    public function publicCode(): string
    {
        return self::PUBLIC_CODES[$this->reason] ?? 'ai_temporarily_unavailable';
    }

    /** هل قد تنجح محاولة لاحقة من المستخدم دون إصلاح في الإعدادات. */
    public function isTransient(): bool
    {
        return in_array($this->reason, self::TRANSIENT, true);
    }

    /**
     * هل يُعاد الطلب تلقائيًا داخل نفس الطلب. انتهاء المهلة لا يُعاد لأنه استهلك المهلة كاملة،
     * وحد الطلبات يُعاد فقط عندما يحدد Gemini مدة انتظار (يتحقق المزود من أنها قصيرة).
     */
    public function shouldRetryNow(): bool
    {
        return in_array($this->reason, [self::UPSTREAM_UNAVAILABLE, self::CONNECTION_FAILED], true)
            || ($this->reason === self::RATE_LIMITED && $this->retryAfterSeconds !== null);
    }

    /** بيانات آمنة للسجلات وللوضع التشخيصي (APP_DEBUG) وأمر الفحص. */
    public function diagnostic(): array
    {
        return array_filter([
            'reason' => $this->reason,
            'http_status' => $this->httpStatus,
            'provider_status' => $this->providerStatus,
            'provider_message' => $this->providerMessage,
            'retry_after' => $this->retryAfterSeconds,
            ...$this->details,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public static function sanitize(?string $value, string $apiKey = ''): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if ($apiKey !== '') {
            $value = str_replace($apiKey, '[REDACTED]', $value);
        }
        $value = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $value) ?? '';
        $value = preg_replace('/AQ\.[0-9A-Za-z_-]{20,}/', '[REDACTED]', $value) ?? '';
        $value = preg_replace('/(?i)(api[_-]?key|key|token|authorization)\s*[:=]\s*\S+/', '$1=[REDACTED]', $value) ?? '';
        $value = preg_replace('/(?i)bearer\s+\S+/', 'Bearer [REDACTED]', $value) ?? '';

        return mb_substr(trim($value), 0, 300);
    }

    private static function hasErrorReason(array $details, string $reason): bool
    {
        foreach ($details as $detail) {
            if (($detail['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }

    /** حصة يومية أو حصة صفرية لا تتجدد خلال دقائق، فلا فائدة من إعادة المحاولة الآن. */
    private static function isLongTermQuota(array $details, ?string $message): bool
    {
        foreach ($details as $detail) {
            foreach (is_array($detail['violations'] ?? null) ? $detail['violations'] : [] as $violation) {
                if (! is_array($violation)) {
                    continue;
                }
                $quotaId = is_string($violation['quotaId'] ?? null) ? $violation['quotaId'] : '';
                if (stripos($quotaId, 'PerDay') !== false || (string) ($violation['quotaValue'] ?? '') === '0') {
                    return true;
                }
            }
        }

        return $message !== null && preg_match('/per\s*day|daily/i', $message) === 1;
    }

    private static function retryAfter(Response $response, array $details): ?int
    {
        foreach ($details as $detail) {
            $delay = $detail['retryDelay'] ?? null;
            if (is_string($delay) && preg_match('/^(\d+(?:\.\d+)?)s$/', trim($delay), $match) === 1) {
                return min(self::MAX_RETRY_AFTER_SECONDS, max(1, (int) ceil((float) $match[1])));
            }
        }

        $header = trim($response->header('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return min(self::MAX_RETRY_AFTER_SECONDS, max(1, (int) $header));
        }

        $timestamp = strtotime($header);

        return $timestamp === false
            ? null
            : min(self::MAX_RETRY_AFTER_SECONDS, max(1, $timestamp - time()));
    }
}
