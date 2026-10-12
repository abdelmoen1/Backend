<?php

namespace App\Services\AI;

use RuntimeException;

class ProjectDiscoveryException extends RuntimeException
{
    /** رسائل آمنة للمؤسسة حسب نوع الفشل؛ لا تتضمن أي تفاصيل من المزود. */
    private const MESSAGES = [
        'ai_temporarily_unavailable' => 'تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا. أعد المحاولة بعد قليل؛ إجاباتك محفوظة.',
        'ai_timeout' => 'استغرقت خدمة الذكاء الاصطناعي وقتًا أطول من المعتاد ولم يكتمل الرد. أعد المحاولة؛ إجاباتك محفوظة.',
        'ai_rate_limited' => 'خدمة الذكاء الاصطناعي مشغولة حاليًا بسبب كثرة الطلبات. انتظر قليلًا ثم أعد المحاولة؛ إجاباتك محفوظة.',
        'ai_quota_exhausted' => 'وصلت خدمة الذكاء الاصطناعي إلى الحد المسموح به حاليًا، لذلك لا يمكن متابعة المحادثة الآن. إجاباتك محفوظة ويمكنك العودة لاحقًا.',
        'ai_invalid_response' => 'تعذر إنشاء سؤال جديد للاكتشاف حاليًا. إجاباتك محفوظة، ويمكنك المحاولة مرة أخرى.',
        'ai_configuration_error' => 'خدمة الذكاء الاصطناعي غير متاحة حاليًا بسبب مشكلة في إعدادات الخادم. إجاباتك محفوظة، ويرجى إبلاغ فريق الدعم.',
    ];

    /** مدة انتظار افتراضية عندما لا يحددها Gemini. */
    private const DEFAULT_RETRY_AFTER = [
        'ai_temporarily_unavailable' => 5,
        'ai_rate_limited' => 30,
    ];

    public readonly ?array $providerDiagnostic;

    public function __construct(public readonly GeminiFailure $failure)
    {
        parent::__construct(self::MESSAGES[$failure->publicCode()]);
        $this->providerDiagnostic = $failure->diagnostic();
    }

    public static function because(string $reason, array $details = [], ?int $httpStatus = null): self
    {
        return new self(new GeminiFailure($reason, $httpStatus, details: $details));
    }

    public function errorCode(): string
    {
        return $this->failure->publicCode();
    }

    public function retryable(): bool
    {
        return $this->failure->isTransient();
    }

    /** ثوانٍ مقترحة قبل إعادة المحاولة؛ لا تُرسل للأخطاء التي لا تُحل بالانتظار القصير. */
    public function retryAfter(): ?int
    {
        if (! $this->retryable()) {
            return null;
        }

        return $this->failure->retryAfterSeconds
            ?? self::DEFAULT_RETRY_AFTER[$this->errorCode()]
            ?? null;
    }
}
