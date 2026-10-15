<?php

namespace App\Services;

use App\Contracts\AuditWriter;
use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\User;
use App\Support\AuditMask;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * HRMS audit and data-access logging.
 *
 * Deliberately mirrors {@see ActivityLogger}'s API shape so call sites read the
 * same way, but writes to the two append-only ledgers instead of `activities`
 * and additionally emits a `hrms` channel line so a change is visible both in
 * the durable trail and in the operational stream (D2.9).
 *
 * Masking is not optional and lives in {@see AuditMask} (shared with the
 * platform audit feed): values whose *name* matches a sensitive pattern are
 * replaced with a mask before they ever reach the database. Names are matched
 * rather than allow-listed because a new salary column must not silently start
 * logging its value.
 */
class HrmsAuditLogger implements AuditWriter
{
    /**
     * Record a change to a business record.
     *
     * `before`/`after` are diffed into `{before, after}` and masked. Pass only
     * the fields that matter — this is a ledger, not a row snapshot dump.
     *
     * Takes the subject as a model rather than the plan's
     * `subjectType, subjectId` pair: a mismatched type/id pair silently
     * produces a ledger row pointing at the wrong record, and the morph class
     * is the only correct source of the type string anyway.
     */
    public function log(
        Model $subject,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?int $actorEmployeeId = null,
    ): HrmsAuditLog {
        $log = HrmsAuditLog::create([
            'actor_user_id' => $actor?->id,
            'actor_employee_id' => $actorEmployeeId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'data' => AuditMask::mask([
                'before' => $before,
                'after' => $after,
            ]),
            'ip_address' => $ipAddress,
        ] + RequestId::columnFor(new HrmsAuditLog));

        // Operational line: identifiers only. The values live in the ledger.
        Log::channel('hrms')->info($action, [
            'entity' => $subject->getMorphClass(),
            'entity_id' => $subject->getKey(),
            'actor_id' => $actor?->id,
            'fields' => $this->changedFields($before, $after),
        ]);

        return $log;
    }

    /**
     * {@see AuditWriter}: a change row addressed by morph class and id rather
     * than a model instance. `$context` is merged beside the masked
     * before/after pair; `$actorId` is a tenant user id.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function recordChange(
        string $action,
        ?string $subjectType,
        int|string|null $subjectId,
        ?array $before,
        ?array $after,
        array $context = [],
        ?int $actorId = null,
        ?string $ipAddress = null,
    ): Model {
        $actor = $actorId ?? auth()->id();

        $log = HrmsAuditLog::create([
            'actor_user_id' => $actor,
            'subject_type' => (string) $subjectType,
            'subject_id' => (int) $subjectId,
            'action' => $action,
            'data' => AuditMask::mask($context + ['before' => $before, 'after' => $after]),
            'ip_address' => $ipAddress ?? request()->ip(),
        ] + RequestId::columnFor(new HrmsAuditLog));

        Log::channel('hrms')->info($action, [
            'entity' => $subjectType,
            'entity_id' => $subjectId,
            'actor_id' => $actor,
            'fields' => $this->changedFields($before, $after),
        ]);

        return $log;
    }

    /**
     * Record a *read* of sensitive data.
     *
     * Separate from {@see Log()} because opening a payslip changes nothing;
     * recording it as a change would pollute the audit ledger.
     */
    public function accessed(
        string $model,
        int $recordId,
        DataAccessAction $action,
        ?array $fields = null,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): HrmsDataAccessLog {
        $log = HrmsDataAccessLog::create([
            'actor_user_id' => $actor?->id,
            'model' => $model,
            'record_id' => $recordId,
            'action' => $action,
            // Field *names* are safe to record — that is the point of the
            // column. Values are never kept, so the stored list is normalised
            // to names whether the caller passed `['gross', 'net']` or
            // `['gross' => 95000]`.
            'fields' => $this->fieldNames($fields),
            'ip_address' => $ipAddress,
        ] + RequestId::columnFor(new HrmsDataAccessLog));

        Log::channel('hrms')->notice('data.'.$action->value, [
            'entity' => $model,
            'entity_id' => $recordId,
            'actor_id' => $actor?->id,
            'fields' => $this->fieldNames($fields),
        ]);

        return $log;
    }

    /** @return Builder<HrmsAuditLog> */
    public function forSubject(Model $subject): Builder
    {
        return HrmsAuditLog::query()
            ->forSubject($subject)
            ->with('actor')
            ->orderByDesc('id');
    }

    /**
     * Reduce a caller-supplied field list to field *names* only.
     *
     * Callers naturally pass either `['gross', 'net']` (a list of names) or
     * `['gross' => 95000]` (a name => value map). `array_values()` on the
     * second form would store the amounts and discard the names — precisely
     * backwards — so the string keys win when they exist.
     *
     * @param  array<array-key, mixed>|null  $fields
     * @return array<int, string>|null
     */
    private function fieldNames(?array $fields): ?array
    {
        if ($fields === null) {
            return null;
        }

        $keys = array_values(array_filter(array_keys($fields), 'is_string'));

        return $keys !== []
            ? $keys
            : array_values(array_filter($fields, 'is_string'));
    }

    /**
     * The field names that actually differ between two states.
     *
     * Returned rather than the values, so the log line answers "what changed"
     * without carrying payroll figures into the log stream.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<int, string>
     */
    private function changedFields(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];

        $keys = array_unique([...array_keys($before), ...array_keys($after)]);

        return array_values(array_filter(
            $keys,
            fn (string $key) => ($before[$key] ?? null) !== ($after[$key] ?? null),
        ));
    }
}
