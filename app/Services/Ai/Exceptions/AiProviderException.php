<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** A provider call failed. The message is safe to log; it never carries a prompt or a credential. */
class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly AiErrorKind $kind,
        string $message,
        public readonly string $provider = '',
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function unsupported(string $provider, string $capability): self
    {
        return new self(AiErrorKind::Unsupported, "Provider {$provider} does not support {$capability} for the configured model.", $provider);
    }
}
