<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use InvalidArgumentException;

/** The model sent arguments that do not match the tool's schema. Reported back to the model, never executed. */
class InvalidToolArguments extends InvalidArgumentException {}
