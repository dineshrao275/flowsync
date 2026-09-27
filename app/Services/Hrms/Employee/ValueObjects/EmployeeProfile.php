<?php

namespace App\Services\Hrms\Employee\ValueObjects;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use App\Models\Hrms\Employee\Employee;

/**
 * Employee/HRMS — the mutable profile of a record, as a readonly value object.
 *
 * Two jobs, both of which the service would otherwise do inline:
 *
 * 1. **Whitelist what a profile write may touch.** `employee_code`, `manager_id`
 *    and `status` are absent on purpose. The code is printed on payslips and
 *    appears in audit trails, the manager has `assignManager()` so the reporting
 *    line can be checked for cycles, and the status has `changeStatus()` so it
 *    leaves a history row. Three doors to one fact is how they drift apart.
 * 2. **Curate what goes to the audit ledger.** {@see self::auditable()} returns
 *    only the non-identifying fields.
 *
 * That second job is deliberately *not* delegated to `HrmsAuditLogger`'s masking
 * alone. The logger masks `email`, `phone` and `date_of_birth`, so an unmasked
 * payload would still be safe today — but `emergency_contact_name` and
 * `nationality` are third-party and personal data that the shared token list
 * does not name, and widening a shared constant to cover one context is how
 * other contexts' expectations get broken. Curating at the source means this
 * context never hands personal data to a logger in the first place, and the
 * masking stays a second line of defence rather than the only one.
 */
final readonly class EmployeeProfile
{
    /**
     * The fields a profile write may set, in the order they appear on the form.
     *
     * @var list<string>
     */
    public const WRITABLE = [
        'name',
        'preferred_name',
        'personal_email',
        'phone',
        'date_of_birth',
        'gender',
        'marital_status',
        'nationality',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'photo_path',
        'joining_date',
        'probation_end_date',
        'confirmation_date',
        'employment_type_id',
        'designation',
        // P3.1's columns. The free-text `designation` above is what the P2.2
        // seed wrote and what a P2.7 backfill left behind; these are the
        // referential replacements, and both are kept because a tenant that
        // has not built a designation catalogue yet still has employees.
        'designation_id',
        'department_id',
        'location_id',
        'work_mode',
        'notes',
    ];

    /**
     * The fields safe to write into `hrms_audit_logs` — enough to answer "what
     * changed and who is this", and nothing that identifies a person.
     *
     * @var list<string>
     */
    private const AUDITABLE = [
        'employee_code',
        'name',
        'preferred_name',
        'designation',
        'work_mode',
        'status',
        'employment_type_id',
        'designation_id',
        'department_id',
        'location_id',
        'manager_id',
        'joining_date',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(private array $attributes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(array_intersect_key($data, array_flip(self::WRITABLE)));
    }

    /**
     * The whitelisted attributes, ready for `fill()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    /**
     * A non-identifying snapshot of a record, for the audit ledger.
     *
     * @return array<string, mixed>
     */
    public static function auditable(Employee $employee): array
    {
        $snapshot = $employee->only(self::AUDITABLE);

        // Enum casts do not survive `only()`, which reads raw attributes.
        $snapshot['work_mode'] = $employee->work_mode?->value;
        $snapshot['status'] = $employee->status?->value;

        if ($employee->joining_date !== null) {
            $snapshot['joining_date'] = $employee->joining_date->toDateString();
        }

        return $snapshot;
    }

    /**
     * The status a new record starts in.
     *
     * An employee created without a stated status is on probation if they have
     * a probation end date, because that combination is what a new hire looks
     * like; otherwise active. Making the default depend on the data beats
     * defaulting to Active and leaving a probationer to look permanently
     * confirmed.
     */
    public function initialStatus(): EmployeeStatus
    {
        $stated = $this->attributes['status'] ?? null;

        if ($stated !== null) {
            return $stated instanceof EmployeeStatus ? $stated : EmployeeStatus::from((string) $stated);
        }

        if (! empty($this->attributes['probation_end_date'])) {
            return EmployeeStatus::Probation;
        }

        return EmployeeStatus::Active;
    }

    public function workMode(): WorkMode
    {
        $stated = $this->attributes['work_mode'] ?? null;

        if ($stated === null) {
            return WorkMode::Office;
        }

        return $stated instanceof WorkMode ? $stated : WorkMode::from((string) $stated);
    }
}
