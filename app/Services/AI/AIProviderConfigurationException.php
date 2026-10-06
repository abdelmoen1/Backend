<?php

namespace App\Services\AI;

use RuntimeException;

class AIProviderConfigurationException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The AI provider is not configured.');
    }
}
