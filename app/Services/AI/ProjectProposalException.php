<?php

namespace App\Services\AI;

use RuntimeException;

class ProjectProposalException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('تعذر إعداد مقترح المشروع حاليًا. حاول مرة أخرى لاحقًا.');
    }
}
