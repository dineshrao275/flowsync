<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/** A tenant used up its AI allowance for the day. Carries which limit, so the UI can say so plainly. */
class AiLimitExceededException extends RuntimeException
{
    public function __construct(public readonly string $limit, public readonly int $allowed)
    {
        parent::__construct(__('Your organisation has reached its daily AI :limit limit (:allowed). It resets tomorrow.', ['limit' => $limit, 'allowed' => $allowed]));
    }
}
