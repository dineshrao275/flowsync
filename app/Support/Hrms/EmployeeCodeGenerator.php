<?php

namespace App\Support\Hrms;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HRMS — allocates the employee code (`EMP-1`, `EMP-2`, …).
 *
 * Mirrors `KeyGenerator::nextTaskKey()`'s intent — a code that is unique by
 * construction, not by a hopeful `max(id) + 1` — but there is no
 * `last_employee_sequence` counter column to bump, so the sequence is derived
 * from the codes already issued and the **unique index is the actual guard**.
 * That makes allocation a candidate, not a reservation, which is why
 * {@see self::retrying()} re-runs the caller's write on a collision instead of
 * handing back a code that was free when it was computed and is taken by the
 * time it is used.
 *
 * Derived from the issued codes rather than the row count so the sequence stays
 * monotonic: deleting the highest employee does not recycle `EMP-42` for
 * somebody else, because a code that has appeared in a payslip, an audit trail
 * or a bank transfer must never mean two different people.
 */
class EmployeeCodeGenerator
{
    /**
     * How many times a collision is retried before giving up.
     *
     * Five is not arbitrary: each retry re-derives the next free code, so five
     * concurrent creations colliding on the same number is already far past
     * anything a single tenant can do in one request.
     */
    private const MAX_ATTEMPTS = 5;

    private const PREFIX = 'EMP-';

    /**
     * Run a write that needs a fresh employee code, retrying on collision.
     *
     * The callback is re-invoked, not just re-coded, so the whole create lands
     * in its own transaction per attempt. This matters on PostgreSQL: a unique
     * violation aborts the enclosing transaction, so retrying inside the failed
     * transaction would fail again on the first statement. It is also why
     * `DB::transaction($callback, attempts: N)` is not usable here — that
     * retries concurrency errors, not uniqueness.
     *
     * @template T
     *
     * @param  callable(string): T  $write  Receives the allocated code.
     * @return T
     */
    public function retrying(callable $write): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn () => $write($this->next()));
            } catch (QueryException $e) {
                if (! $this->isCodeCollision($e)) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages([
            'form' => 'Could not allocate a unique employee code. Please try again.',
        ]);
    }

    /**
     * The next candidate code, e.g. "EMP-7".
     *
     * Not a reservation — see {@see self::retrying()}.
     */
    public function next(): string
    {
        return self::PREFIX.$this->nextSequence();
    }

    /**
     * One past the highest sequence already issued.
     *
     * Parsed rather than counted, so a code with gaps (EMP-1, EMP-9) does not
     * hand out EMP-2 — which is free, but would look like it belongs between
     * two records that are not adjacent.
     */
    private function nextSequence(): int
    {
        $highest = Employee::query()
            ->where('employee_code', 'like', self::PREFIX.'%')
            ->pluck('employee_code')
            ->map(fn (string $code) => (int) substr($code, strlen(self::PREFIX)))
            ->filter(fn (int $sequence) => $sequence > 0)
            ->max();

        return ((int) $highest) + 1;
    }

    /**
     * Whether this failure is the code colliding, rather than any other
     * database error.
     *
     * This reads the *driver's* message (`$e->errorInfo[2]`), never
     * `$e->getMessage()`. Laravel appends the failing statement to the
     * exception message, and every insert into `employees` names
     * `employee_code` — so matching the full text reports a unique violation
     * on `user_id`, `email` or anything else as a code collision, and then
     * retries it five times before reporting a code failure that never
     * happened. The two are indistinguishable in the message and obvious in
     * `errorInfo`.
     *
     * SQLite says `UNIQUE constraint failed: employees.employee_code`;
     * PostgreSQL says `duplicate key value violates unique constraint
     * "employees_employee_code_unique"`. Both name the column, so one
     * substring test covers them.
     */
    private function isCodeCollision(QueryException $e): bool
    {
        $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if (stripos($driverMessage, 'employee_code') === false) {
            return false;
        }

        return str_contains(strtolower($driverMessage), 'unique')
            || stripos($driverMessage, 'duplicate') !== false;
    }
}
