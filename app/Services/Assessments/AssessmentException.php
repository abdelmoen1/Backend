<?php

namespace App\Services\Assessments;

use RuntimeException;

/** رفض متوقع في التقييم برسالة عربية آمنة للعرض ورمز ثابت تتعامل معه الواجهة. */
class AssessmentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $context = [],
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function ai(string $publicCode, bool $retryable, string $operation): self
    {
        $message = match ($operation) {
            'generation' => $retryable
                ? 'تعذر إعداد أسئلة التقييم الآن. لم يُسجَّل أي شيء؛ حاول مرة أخرى بعد قليل.'
                : 'خدمة إعداد التقييمات غير متاحة حاليًا. لم يُسجَّل أي شيء؛ حاول لاحقًا.',
            default => $retryable
                ? 'تعذر تصحيح الإجابات الآن. إجاباتك محفوظة؛ أعد محاولة التصحيح بعد قليل.'
                : 'خدمة التصحيح غير متاحة حاليًا. إجاباتك محفوظة ويمكن تصحيحها لاحقًا.',
        };

        return new self($message, $publicCode, 503, retryable: $retryable);
    }
}
