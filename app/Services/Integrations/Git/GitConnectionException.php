<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use RuntimeException;

/** A connect/disconnect rule was broken; `status` is the HTTP status the controller answers with. */
class GitConnectionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
