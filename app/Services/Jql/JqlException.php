<?php

declare(strict_types=1);

namespace App\Services\Jql;

/** A query the user typed that cannot be read; the message is shown to them verbatim. */
class JqlException extends \RuntimeException {}
