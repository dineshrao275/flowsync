<?php

namespace App\Services\Hrms\Shared\ValueObjects;

use App\Enums\Hrms\ApproverType;

/**
 * The rule for one approval step: who is expected to act.
 *
 * Expressing a step as a *rule* rather than a fixed user is what lets a re-org
 * change who must act without rewriting approval history.
 *
 * For `Manager` and `DepartmentHead` the caller — the context that owns the
 * employee graph — resolves the person and passes `userId` (plus the
 * `employeeId` for the record). This engine never queries `employees` itself;
 * that keeps the Shared context free of a dependency on a table owned by
 * another context (D2.4).
 */
final readonly class ApproverSpec
{
    public function __construct(
        public ApproverType $type,
        public ?int $roleId = null,
        public ?int $userId = null,
        public ?int $employeeId = null,
    ) {}

    /** @param array{type: string, role_id?: int|null, user_id?: int|null, employee_id?: int|null} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] instanceof ApproverType ? $data['type'] : ApproverType::from($data['type']),
            roleId: $data['role_id'] ?? null,
            userId: $data['user_id'] ?? null,
            employeeId: $data['employee_id'] ?? null,
        );
    }

    /**
     * A `role` step: anybody holding the given role may act.
     */
    public static function role(int $roleId): self
    {
        return new self(type: ApproverType::Role, roleId: $roleId);
    }

    /** A `user` step: one specific person may act. */
    public static function user(int $userId, ?int $employeeId = null): self
    {
        return new self(type: ApproverType::User, userId: $userId, employeeId: $employeeId);
    }

    public function hasCandidate(): bool
    {
        return match ($this->type) {
            ApproverType::Role => $this->roleId !== null,
            ApproverType::User, ApproverType::Manager, ApproverType::DepartmentHead => $this->userId !== null,
        };
    }

    /**
     * Whether a person holding the given role may act on a `role` step.
     */
    public function acceptsRole(string $roleSlug): bool
    {
        return $this->type === ApproverType::Role && $roleSlug !== '';
    }
}
