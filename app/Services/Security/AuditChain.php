<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Support\TenantDatabaseManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hash chain over the platform audit trail (P8.6).
 *
 * hash = sha256(prev_hash | canonical(id, subject, action, data, actor, ip,
 * created_at)); rows are sealed in id order under a short cache lock so two
 * writers cannot fork the chain. Editing, deleting or re-ordering a sealed row
 * makes `verify()` report the first id where the links stop agreeing. Rows
 * removed by retention leave a tombstone (id + hash), which keeps the chain
 * continuous without keeping the content. The head hash is what an auditor
 * pins from an export: anything older that changes later no longer reproduces it.
 *
 * Honest limits: this is tamper EVIDENCE, not prevention - someone who can
 * rewrite the whole table AND recompute every later hash is only caught if the
 * head was recorded elsewhere (hence the head in every export). Rows written
 * before the first seal are reported as `legacy`, not verified.
 */
class AuditChain
{
    private const LOCK = 'audit-chain-seal';

    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    /** Seal the new row (and any earlier unsealed rows a concurrent writer left behind). */
    public function seal(AuditLog $log): void
    {
        $seal = function () use ($log): void {
            $db = $this->db();
            [$prevId, $prevHash] = $this->tip($db);

            $pending = $db->table('audit_logs')->whereNull('hash')->where('id', '<=', $log->id)
                ->when($prevId !== null, fn ($q) => $q->where('id', '>', $prevId))
                // With nothing sealed yet the chain starts at this row; older rows stay legacy.
                ->when($prevId === null, fn ($q) => $q->where('id', $log->id))
                ->orderBy('id')->get();

            foreach ($pending as $row) {
                $hash = $this->hashRow($this->normalize($row), $prevHash);
                $db->table('audit_logs')->where('id', $row->id)->update(['prev_hash' => $prevHash, 'hash' => $hash]);
                $prevHash = $hash;
            }
        };

        try {
            try {
                Cache::lock(self::LOCK, 10)->block(5, $seal);
            } catch (LockTimeoutException) {
                // Never lose an audit write over lock contention: seal unlocked; verify() will say if it forked.
                $seal();
            }
        } catch (QueryException $e) {
            // The row is already written; a database that has not run the chain migration yet
            // must keep auditing (it will seal from the first row after migrating).
            Log::warning('audit chain seal skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{ok: bool, checked: int, legacy: int, tombstones: int, head: ?string, break: ?array{id: int, reason: string}}
     */
    public function verify(): array
    {
        $db = $this->db();
        $tombstones = $db->table('audit_log_tombstones')->orderBy('audit_log_id')->get()->keyBy('audit_log_id');
        $report = ['ok' => true, 'checked' => 0, 'legacy' => 0, 'tombstones' => $tombstones->count(), 'head' => null, 'break' => null];

        $prev = null;
        $started = false;
        $pendingTombstones = $tombstones->values();
        $t = 0;

        $absorbTombstonesUpTo = function (int $id) use (&$pendingTombstones, &$t, &$prev, &$started, &$report): void {
            while (isset($pendingTombstones[$t]) && $pendingTombstones[$t]->audit_log_id < $id) {
                $ts = $pendingTombstones[$t++];
                if ($started && $ts->prev_hash !== $prev && $report['break'] === null) {
                    $report['break'] = ['id' => (int) $ts->audit_log_id, 'reason' => 'tombstone does not follow the previous link'];
                }
                $prev = $ts->hash;
                $started = true;
            }
        };

        foreach ($db->table('audit_logs')->orderBy('id')->cursor() as $row) {
            $absorbTombstonesUpTo((int) $row->id);
            if ($report['break'] !== null) {
                break;
            }

            if ($row->hash === null) {
                if ($started) {
                    $report['break'] = ['id' => (int) $row->id, 'reason' => 'unsealed row inside the sealed chain'];
                    break;
                }
                $report['legacy']++;

                continue;
            }

            $expectedPrev = $started ? $prev : $row->prev_hash;
            if ($row->prev_hash !== $expectedPrev) {
                $report['break'] = ['id' => (int) $row->id, 'reason' => 'link to the previous row was altered or a row is missing'];
                break;
            }
            if (! hash_equals($row->hash, $this->hashRow($this->normalize($row), $row->prev_hash))) {
                $report['break'] = ['id' => (int) $row->id, 'reason' => 'row content does not match its hash'];
                break;
            }

            $prev = $row->hash;
            $started = true;
            $report['checked']++;
        }

        if ($report['break'] === null) {
            $absorbTombstonesUpTo(PHP_INT_MAX); // tombstones newer than every surviving row
        }

        $report['head'] = $prev;
        $report['ok'] = $report['break'] === null;

        return $report;
    }

    /** The newest link: what an export pins so later tampering with anything before it is detectable. */
    public function head(): ?string
    {
        return $this->tip($this->db())[1];
    }

    /**
     * Replace a row by a tombstone (called by retention, inside its transaction).
     *
     * @param  object  $row  audit_logs row with hash set
     */
    public function tombstone(object $row, ?int $tenantId, string $reason = 'retention'): void
    {
        $db = $this->db();
        $db->table('audit_log_tombstones')->insert([
            'audit_log_id' => $row->id, 'prev_hash' => $row->prev_hash, 'hash' => $row->hash,
            'tenant_id' => $tenantId, 'reason' => $reason, 'pruned_at' => now(),
        ]);
        $db->table('audit_logs')->where('id', $row->id)->delete();
    }

    /** @return array{0: ?int, 1: ?string} id + hash of the newest sealed row or tombstone */
    private function tip(ConnectionInterface $db): array
    {
        $row = $db->table('audit_logs')->whereNotNull('hash')->orderByDesc('id')->first(['id', 'hash']);
        $tomb = $db->table('audit_log_tombstones')->orderByDesc('audit_log_id')->first(['audit_log_id', 'hash']);

        if ($tomb && (! $row || $tomb->audit_log_id > $row->id)) {
            return [(int) $tomb->audit_log_id, $tomb->hash];
        }

        return $row ? [(int) $row->id, $row->hash] : [null, null];
    }

    /** @return array<string, mixed> the fields the hash covers, in a stable shape */
    private function normalize(object $row): array
    {
        $data = is_string($row->data) ? json_decode($row->data, true) : $row->data;

        return [
            'id' => (int) $row->id,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
            'action' => $row->action,
            'data' => $this->canonical($data),
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'ip_address' => $row->ip_address,
            'created_at' => $row->created_at === null ? null : Carbon::parse($row->created_at)->format('Y-m-d H:i:s'),
        ];
    }

    /** @param  array<string, mixed>  $fields */
    private function hashRow(array $fields, ?string $prev): string
    {
        return hash('sha256', ($prev ?? '').'|'.json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Key order must not matter (PostgreSQL jsonb reorders keys). */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map($this->canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->dbm->centralConnectionName());
    }
}
