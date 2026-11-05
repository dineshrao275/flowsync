<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Errors\ErrorRecorder;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * The single capture funnel for application errors.
 *
 * Laravel's exception handler reports to the default logger, so this listener
 * sees both uncaught exceptions (`context.exception`) and explicit
 * `Log::error/warning/critical` calls — from HTTP, queue workers and console
 * commands. It runs after Monolog has written the file line, so a database
 * failure here can never break logging; the recorder itself swallows failures.
 */
class RecordApplicationError
{
    public function __construct(private readonly ErrorRecorder $recorder) {}

    public function handle(MessageLogged $event): void
    {
        if (! config('error_logging.enabled', true)) {
            return;
        }

        // Frontend beacons are stored by ClientLogController (source=frontend);
        // its mirrored file log must not create a second backend row.
        if (($event->context['error_capture'] ?? true) === false) {
            return;
        }

        $level = strtolower($event->level);

        if (! in_array($level, config('error_logging.levels', []), true)) {
            return;
        }

        if ($this->ignoredPath($event->context['path'] ?? null)) {
            return;
        }

        $exception = $event->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            if ($this->ignoredException($exception)) {
                return;
            }

            $this->recorder->recordException($exception, null, 'backend', $event->context + ['log_level' => $level]);

            return;
        }

        $this->recorder->recordMessage($level, $event->message, $event->context, null, 'backend');
    }

    private function ignoredException(Throwable $e): bool
    {
        foreach (config('error_logging.ignore_exceptions', []) as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function ignoredPath(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        return in_array(ltrim($path, '/'), config('error_logging.ignore_paths', []), true);
    }
}
