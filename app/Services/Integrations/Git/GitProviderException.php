<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use RuntimeException;

/**
 * A provider call failed. The message is safe to show; the response body never travels
 * (it can echo repository names or tokens), only the status and an optional retry delay.
 */
class GitProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429 || ($this->status === 403 && $this->retryAfter !== null);
    }
}
