<?php

namespace App\Services\Projects;

use RuntimeException;

/** رفض قاعدة عمل في التقديم أو تكوين الفريق، برسالة عربية آمنة للعرض ورمز ثابت ورمز HTTP. */
class ProjectActionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
