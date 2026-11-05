<?php

declare(strict_types=1);

namespace App\Services\Errors;

use App\Models\ErrorLog;
use App\Models\ErrorLogNote;
use App\Models\ErrorLogStatusHistory;
use App\Models\ErrorOccurrence;

/** API shapes for the Super Admin error console. */
class ErrorPresenter
{
    /** @return array<string, mixed> */
    public function item(ErrorLog $log, ?string $tenantName = null): array
    {
        return [
            'id' => $log->id,
            'fingerprint' => $log->fingerprint,
            'exception_class' => $log->exception_class,
            'message' => $log->message,
            'severity' => $log->severity,
            'status' => $log->status,
            'module' => $log->module,
            'source' => $log->source,
            'environment' => $log->environment,
            'tenant_id' => $log->tenant_id,
            'tenant' => $tenantName,
            'path' => $log->path,
            'method' => $log->method,
            'http_status' => $log->http_status,
            'occurrences' => $log->occurrences,
            'first_seen_at' => $log->first_seen_at?->toIso8601String(),
            'last_seen_at' => $log->last_seen_at?->toIso8601String(),
            'resolved_at' => $log->resolved_at?->toIso8601String(),
            'ignored_at' => $log->ignored_at?->toIso8601String(),
            'recurred' => $log->recurred(),
        ];
    }

    /**
     * @param  iterable<ErrorLogNote>  $notes
     * @param  iterable<ErrorLogStatusHistory>  $history
     * @param  iterable<ErrorOccurrence>  $occurrences
     * @return array<string, mixed>
     */
    public function detail(
        ErrorLog $log,
        ?string $tenantName = null,
        iterable $occurrences = [],
        iterable $notes = [],
        iterable $history = [],
    ): array {
        return $this->item($log, $tenantName) + [
            'request_id' => $log->request_id,
            'user_id' => $log->user_id,
            'file' => $log->file,
            'line' => $log->line,
            'stack_trace' => $log->stack_trace,
            'context' => $log->context ?? [],
            'occurrences_preview' => collect($occurrences)->map(fn (ErrorOccurrence $o) => $this->occurrence($o))->values()->all(),
            'notes' => collect($notes)->map(fn (ErrorLogNote $n) => $this->note($n))->values()->all(),
            'history' => collect($history)->map(fn (ErrorLogStatusHistory $h) => $this->history($h))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function occurrence(ErrorOccurrence $occurrence): array
    {
        return [
            'id' => $occurrence->id,
            'occurred_at' => $occurrence->occurred_at?->toIso8601String(),
            'request_id' => $occurrence->request_id,
            'tenant_id' => $occurrence->tenant_id,
            'user_id' => $occurrence->user_id,
            'path' => $occurrence->path,
            'method' => $occurrence->method,
            'http_status' => $occurrence->http_status,
            'context' => $occurrence->context ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function note(ErrorLogNote $note): array
    {
        return [
            'id' => $note->id,
            'author_id' => $note->author_id,
            'author_name' => $note->author_name,
            'body' => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function history(ErrorLogStatusHistory $history): array
    {
        return [
            'id' => $history->id,
            'from_status' => $history->from_status,
            'to_status' => $history->to_status,
            'actor_id' => $history->actor_id,
            'actor_name' => $history->actor_name,
            'note' => $history->note,
            'created_at' => $history->created_at?->toIso8601String(),
        ];
    }
}
