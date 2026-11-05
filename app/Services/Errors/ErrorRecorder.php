<?php

declare(strict_types=1);

namespace App\Services\Errors;

use App\Models\ErrorLog;
use App\Models\ErrorOccurrence;
use App\Support\RequestId;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Writes one rollup row per fingerprint and one occurrence per event to the
 * central database.
 *
 * This service is on the logging path: it must never throw, never log (that
 * would recurse) and never touch a tenant connection. It is bound `scoped`, so
 * the "does the table exist yet?" answer is cached per request/job and a
 * long-lived worker cannot keep a stale "no" after a deploy migrates the table.
 */
class ErrorRecorder
{
    private static bool $busy = false;

    private static bool $inJob = false;

    private ?bool $ready = null;

    public function __construct(
        private readonly ErrorFingerprint $fingerprints,
        private readonly ErrorSanitizer $sanitizer,
        private readonly ErrorCategorizer $categorizer,
    ) {}

    /** Called by the queue events so a worker's errors are labelled `queue`. */
    public static function markJob(bool $inJob): void
    {
        self::$inJob = $inJob;
    }

    /**
     * @param  array<string, mixed>  $extra  logger context (never stored raw)
     */
    public function recordException(Throwable $e, ?Request $request = null, string $source = 'backend', array $extra = []): ?ErrorLog
    {
        $request ??= $this->currentRequest();
        $capturedAt = now();
        $path = $request?->path() ?? (is_string($extra['path'] ?? null) ? ltrim($extra['path'], '/') : null);
        $module = $this->categorizer->categorize($e::class, $path);

        return $this->persist([
            'fingerprint' => $this->fingerprints->make($e::class, $e->getMessage(), $e->getFile(), $module),
            'exception_class' => $e::class,
            'message' => $this->sanitizer->message($e->getMessage() !== '' ? $e->getMessage() : $e::class),
            'severity' => $this->severity($extra['log_level'] ?? 'error'),
            'module' => $module,
            'source' => $this->source($request, $source),
            'environment' => app()->environment(),
            'tenant_id' => $this->tenantId($request),
            'request_id' => $this->requestId($request, $extra),
            'user_id' => $request?->user()?->id,
            'path' => $path,
            'method' => $request?->method() ?? (is_string($extra['method'] ?? null) ? $extra['method'] : null),
            'http_status' => is_numeric($extra['status'] ?? null) ? (int) $extra['status'] : null,
            'file' => $this->fingerprints->relativePath($e->getFile()) ?: null,
            'line' => $e->getLine(),
            'stack_trace' => $this->sanitizer->stack($e->getTraceAsString()),
            'context' => $this->sanitizer->context(Arr::except($extra, [
                'exception', 'log_level', 'status', 'path', 'method', 'request_id', 'file', 'line', 'stack',
            ])),
        ], $capturedAt);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordMessage(string $level, string $message, array $context = [], ?Request $request = null, string $source = 'backend'): ?ErrorLog
    {
        $level = strtolower($level) === 'warn' ? 'warning' : strtolower($level);

        if (! in_array($level, config('error_logging.levels', []), true)) {
            return null;
        }

        $request ??= $this->currentRequest();

        if (($context['exception'] ?? null) instanceof Throwable) {
            return $this->recordException($context['exception'], $request, $source, $context + ['log_level' => $level]);
        }

        $path = is_string($context['path'] ?? null)
            ? ltrim($context['path'], '/')
            : $request?->path();
        $module = $this->categorizer->categorize(null, $path);
        $file = is_string($context['file'] ?? null) ? $context['file'] : null;

        return $this->persist([
            'fingerprint' => $this->fingerprints->make(null, $message, $file, $module),
            'exception_class' => null,
            'message' => $this->sanitizer->message($message),
            'severity' => $this->severity($level),
            'module' => $module,
            'source' => $this->source($request, $source),
            'environment' => app()->environment(),
            'tenant_id' => $this->tenantId($request),
            'request_id' => $this->requestId($request, $context),
            'user_id' => $request?->user()?->id ?? (is_numeric($context['user_id'] ?? null) ? (int) $context['user_id'] : null),
            'path' => $path,
            'method' => $request?->method() ?? (is_string($context['method'] ?? null) ? $context['method'] : null),
            'http_status' => is_numeric($context['status'] ?? null) ? (int) $context['status'] : null,
            'file' => $file !== null ? $this->fingerprints->relativePath($file) : null,
            'line' => is_numeric($context['line'] ?? null) ? (int) $context['line'] : null,
            'stack_trace' => $this->sanitizer->stack(is_string($context['stack'] ?? null) ? $context['stack'] : null),
            'context' => $this->sanitizer->context(Arr::except($context, [
                'exception', 'log_level', 'status', 'path', 'method', 'request_id', 'file', 'line', 'stack',
            ])),
        ], now());
    }

    /** @param  array<string, mixed>  $attributes */
    private function persist(array $attributes, Carbon $at): ?ErrorLog
    {
        if (! config('error_logging.enabled', true) || self::$busy || ! $this->ready()) {
            return null;
        }

        if (($attributes['fingerprint'] ?? '') === '' || ($attributes['message'] ?? '') === '') {
            return null;
        }

        self::$busy = true;

        try {
            return ErrorLog::query()->getConnection()->transaction(function () use ($attributes, $at): ErrorLog {
                $log = ErrorLog::query()
                    ->where('fingerprint', $attributes['fingerprint'])
                    ->lockForUpdate()
                    ->first();

                if ($log === null) {
                    $log = ErrorLog::create($attributes + [
                        'status' => ErrorLog::STATUS_OPEN,
                        'occurrences' => 1,
                        'first_seen_at' => $at,
                        'last_seen_at' => $at,
                    ]);
                } else {
                    $log->forceFill([
                        'occurrences' => $log->occurrences + 1,
                        'last_seen_at' => $at,
                        'tenant_id' => $attributes['tenant_id'] ?? $log->tenant_id,
                        'request_id' => $attributes['request_id'] ?? $log->request_id,
                        'user_id' => $attributes['user_id'] ?? $log->user_id,
                        'path' => $attributes['path'] ?? $log->path,
                        'method' => $attributes['method'] ?? $log->method,
                        'http_status' => $attributes['http_status'] ?? $log->http_status,
                        'file' => $attributes['file'] ?? $log->file,
                        'line' => $attributes['line'] ?? $log->line,
                        'stack_trace' => $attributes['stack_trace'] ?? $log->stack_trace,
                        'context' => $attributes['context'] ?? $log->context,
                    ])->save();
                }

                ErrorOccurrence::create([
                    'error_log_id' => $log->id,
                    'occurred_at' => $at,
                    'request_id' => $attributes['request_id'] ?? null,
                    'tenant_id' => $attributes['tenant_id'] ?? null,
                    'user_id' => $attributes['user_id'] ?? null,
                    'path' => $attributes['path'] ?? null,
                    'method' => $attributes['method'] ?? null,
                    'http_status' => $attributes['http_status'] ?? null,
                    'context' => $attributes['context'] ?? null,
                ]);

                return $log;
            });
        } catch (QueryException) {
            // Two workers can insert the same new fingerprint at once. The
            // loser still counts the occurrence rather than dropping the event.
            return $this->rollupOnly($attributes, $at);
        } catch (Throwable) {
            return null;
        } finally {
            self::$busy = false;
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function rollupOnly(array $attributes, Carbon $at): ?ErrorLog
    {
        try {
            $log = ErrorLog::query()->where('fingerprint', $attributes['fingerprint'])->first();

            if ($log === null) {
                return null;
            }

            $log->forceFill(['occurrences' => $log->occurrences + 1, 'last_seen_at' => $at])->save();

            return $log;
        } catch (Throwable) {
            return null;
        }
    }

    private function ready(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        try {
            $this->ready = Schema::connection((new ErrorLog)->getConnectionName())->hasTable('error_logs');
        } catch (Throwable) {
            $this->ready = false;
        }

        return $this->ready;
    }

    private function severity(string $level): string
    {
        $level = strtolower($level);

        return in_array($level, ErrorLog::SEVERITIES, true) ? $level : 'error';
    }

    private function source(?Request $request, string $source): string
    {
        if ($source !== 'backend') {
            return in_array($source, ErrorLog::SOURCES, true) ? $source : 'backend';
        }

        if ($request !== null) {
            return 'backend';
        }

        if (self::$inJob) {
            return 'queue';
        }

        return app()->runningInConsole() ? 'console' : 'backend';
    }

    private function tenantId(?Request $request): ?int
    {
        $id = app(TenantContext::class)->currentId();

        if ($id !== null) {
            return (int) $id;
        }

        if ($request?->hasSession()) {
            $id = $request->session()->get('login.tenant_id') ?? $request->session()->get('impersonate.tenant_id');

            return is_numeric($id) ? (int) $id : null;
        }

        return null;
    }

    /** @param  array<string, mixed>  $context */
    private function requestId(?Request $request, array $context): ?string
    {
        $id = RequestId::current() ?? ($context['request_id'] ?? null) ?? $request?->attributes->get('request_id');

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 64) : null;
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? request() : null;
    }
}
