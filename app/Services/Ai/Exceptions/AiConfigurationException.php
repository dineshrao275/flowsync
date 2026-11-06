<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** The AI configuration cannot be used as written. Lists every problem found, not just the first. */
class AiConfigurationException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('AI configuration is invalid: '.implode('; ', $problems));
    }
}
