<?php

namespace App\Services\AI;

use RuntimeException;

class ProjectDiscoveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?array $providerDiagnostic = null
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self('تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا.');
    }

    public static function providerFailure(
        int $httpStatus,
        ?string $providerStatus,
        ?string $providerMessage,
        string $apiKey
    ): self {
        $message = $providerMessage === null
            ? null
            : self::sanitize($providerMessage, $apiKey);

        return new self('تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا.', [
            'http_status' => $httpStatus,
            'provider_status' => self::sanitizeValue($providerStatus),
            'provider_message' => $message,
        ]);
    }

    private static function sanitize(?string $value, string $apiKey): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace($apiKey, '[REDACTED]', $value);
        $value = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $value) ?? '';
        $value = preg_replace('/(?i)(api[_-]?key|key|token|authorization)\s*[:=]\s*\S+/', '$1=[REDACTED]', $value) ?? '';
        $value = preg_replace('/(?i)bearer\s+\S+/', 'Bearer [REDACTED]', $value) ?? '';

        return mb_substr(trim($value), 0, 300);
    }

    private static function sanitizeValue(?string $value): ?string
    {
        return $value === null ? null : mb_substr(trim($value), 0, 80);
    }
}
