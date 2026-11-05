<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Smart;

use RuntimeException;

/** A command that is understood but must not run (feature off, nothing to do, a rule says no). The message is the audited reason. */
class SmartCommitSkipped extends RuntimeException {}
