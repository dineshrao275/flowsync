<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Platform\AuditFeed;
use App\Services\PlatformAudit;
use App\Services\Security\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only platform activity feed combining central `audit_logs` with
 * `impersonation_logs` (rendered as audit-style rows).
 *
 * Pagination happens in SQL over the union of the two sources (H-7), not over
 * an in-memory merge of the latest 100 rows each: the old cap silently hid
 * everything older than 100 rows per source, so the feed's `total` and its
 * page count were both wrong on any busy platform. Ordering is
 * `event_at desc, src asc, id desc` — `src` before `id` because ids from two
 * tables are not comparable, and it keeps `audit` rows ahead of
 * `impersonation` rows on a second-precision tie.
 */
class AuditLogsController extends Controller
{
    private const EXPORT_LIMIT = 10000;

    /**
     * @return array<string, list<mixed>>
     */
    private function filters(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(['audit', 'impersonation', 'all'])],
            'q' => ['nullable', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate($this->filters() + [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $data['per_page'] ?? 25;
        $page = max(1, (int) $request->query('page', 1));

        $events = app(AuditFeed::class)->orderedEvents($data);
        $total = (clone $events)->count();

        $rows = $events
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $items = app(AuditFeed::class)->hydrate($rows);

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Stream the same filtered feed as CSV, capped at {@see EXPORT_LIMIT} rows.
     *
     * The export itself is an audited action: it writes an `audit.exported`
     * row (who exported what scope) before the first byte is streamed, so a
     * bulk read of the platform trail is answerable in the trail.
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate($this->filters());

        $rows = app(AuditFeed::class)->orderedEvents($data)->limit(self::EXPORT_LIMIT)->get();

        $items = app(AuditFeed::class)->hydrate($rows);
        $head = app(AuditChain::class)->head();

        app(PlatformAudit::class)->record(
            $request,
            'audit.exported',
            null,
            null,
            [
                'type' => $data['type'] ?? 'all',
                'q' => $data['q'] ?? null,
                'tenant_id' => $data['tenant_id'] ?? null,
                'rows' => $items->count(),
                // Pinning this lets a later reader prove nothing before it was altered (P8.6).
                'chain_head' => $head,
            ],
        );

        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'created_at', 'type', 'action', 'actor', 'actor_email',
                'subject_type', 'subject_id', 'ip_address', 'tenant', 'data', 'hash', 'prev_hash',
            ]);

            foreach ($items as $row) {
                fputcsv($out, [
                    $row['created_at'],
                    $row['type'],
                    $row['action'],
                    $row['actor']['name'] ?? '',
                    $row['actor']['email'] ?? '',
                    $row['subject_type'] ?? '',
                    $row['subject_id'] ?? '',
                    $row['ip_address'] ?? '',
                    $row['tenant']['name'] ?? '',
                    json_encode($row['data']),
                    $row['hash'] ?? '',
                    $row['prev_hash'] ?? '',
                ]);
            }

            fclose($out);
        }, 'audit-logs-'.now()->format('Y-m-d-Hi').'.csv', [
            'Content-Type' => 'text/csv',
            'X-Audit-Chain-Head' => $head ?? '',
        ]);
    }

    /** Walk the hash chain; the same answer `php artisan audit:verify` gives. */
    public function verifyChain(): JsonResponse
    {
        return response()->json(app(AuditChain::class)->verify());
    }
}
