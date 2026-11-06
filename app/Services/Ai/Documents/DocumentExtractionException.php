<?php

declare(strict_types=1);

namespace App\Services\Ai\Documents;

use RuntimeException;

/** A file could not be turned into text. The message is safe to show the user. */
class DocumentExtractionException extends RuntimeException {}
