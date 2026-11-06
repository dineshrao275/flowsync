<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

enum AiErrorKind: string
{
    case RateLimit = 'rate_limit';
    case Timeout = 'timeout';
    case Auth = 'auth';
    case Unsupported = 'unsupported';
    case BadOutput = 'bad_output';
    case Unavailable = 'unavailable';
    case BadRequest = 'bad_request';

    /** Only transient failures are worth retrying; everything else would fail the same way again. */
    public function retryable(): bool
    {
        return in_array($this, [self::RateLimit, self::Timeout, self::Unavailable], true);
    }

    /** HTTP status the API answers with when this reaches a client. */
    public function httpStatus(): int
    {
        return match ($this) {
            self::RateLimit => 429,
            self::Timeout => 504,
            self::Unsupported, self::BadRequest => 422,
            self::Auth, self::Unavailable, self::BadOutput => 502,
        };
    }
}
