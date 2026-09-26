<?php

namespace App\Services;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\User;
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
 * Masking is not optional. Payroll and people data is sensitive, so values
 * whose *name* matches a sensitive pattern are replaced with a mask before
 * they ever reach the database. Names are matched rather than allow-listed
 * because a new salary column must not silently start logging its value.
 */
class HrmsAuditLogger
{
    /**
     * Attribute/field name tokens that must never be logged verbatim.
     *
     * Matched token-by-token, never as raw substrings: `'esi'` is a substring
     * of `designation`, `'pan'` of `company` and `'pay'` of `repayment`, and
     * masking those would silently blank half the audit trail. A short token
     * like `pan` has to be a whole word or it is useless.
     */
    private const SENSITIVE_TOKENS = [
        'password', 'passwd', 'secret', 'token',
        'salary', 'salaries', 'wage', 'wages', 'pay', 'pays', 'payslip',
        'ctc', 'compensation', 'bonus', 'remuneration', 'emoluments',
        'bank', 'banking', 'iban', 'swift', 'account', 'acct',
        'pan', 'uan', 'esi', 'pf', 'ifsc', 'aadhaar', 'passport',
        'national', 'nid',
        'dob', 'birth', 'birthday',
        'address', 'phone', 'mobile', 'email',
        'medical', 'diagnosis', 'health',
        'ssn', 'sin', 'nino', 'cpf',
    ];

    /**
     * Multi-word field names that are sensitive as a phrase, matched against
     * the whole normalised name.
     */
    private const SENSITIVE_PHRASES = [
        'date_of_birth',
        'bank_account',
        'account_number',
        'national_id',
    ];

    private const MASK = '***';

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
            'data' => $this->mask([
                'before' => $before,
                'after' => $after,
            ]),
            'ip_address' => $ipAddress,
        ]);

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
        ]);

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

    /**
     * Replace sensitive values with a mask, recursively.
     *
     * Keyed on the field *name* (D2.9). Matching is token-based so a new salary
     * column cannot slip through, while a harmless name that merely contains a
     * sensitive fragment (`designation`, `company`) is not needlessly blanked.
     *
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    private function mask(?array $data, ?string $key = null): ?array
    {
        if ($data === null) {
            return null;
        }

        $masked = [];

        foreach ($data as $name => $value) {
            $field = is_string($name) ? $name : $key;

            $masked[$name] = $this->isSensitive($field)
                ? self::MASK
                : (is_array($value) ? $this->mask($value, $field) : $value);
        }

        return $masked;
    }

    private function isSensitive(?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        $name = $this->normalise($field);

        if (in_array($name, self::SENSITIVE_PHRASES, true)) {
            return true;
        }

        foreach ($this->tokenize($name) as $token) {
            if (in_array($token, self::SENSITIVE_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case a field name and collapse separators into `_` tokens, so
     * `basic_salary`, `basicSalary` and `Basic Salary` all normalise the same.
     *
     * The two insertion rules are both needed: the first splits a camelCase
     * hump, the second splits an acronym run from a following word. Without
     * the second, `ESI Number` becomes `E_SI_Number` and the `esi` token is
     * lost — which would silently start logging ESI numbers verbatim.
     */
    private function normalise(string $field): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $field) ?? $field;
        $spaced = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '_', $spaced) ?? $spaced;

        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $spaced), '_'));
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $name): array
    {
        return array_values(array_filter(explode('_', $name), fn (string $part) => $part !== ''));
    }
}
