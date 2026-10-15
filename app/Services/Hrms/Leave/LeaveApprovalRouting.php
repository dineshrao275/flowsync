<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;

/**
 * Leave/HRMS — who decides an ask, in which order.
 *
 * Since approvals v2 (P2.5) the chain is template-driven: the default is the
 * one this class used to hard-code — manager, then department head (falling
 * back to the manager), then anyone holding the HR manager role (omitted when
 * the requester holds it) — and a tenant edits it under `leave` /
 * `leave_exemption` in `approval_templates`. The resolution rules live in
 * {@see ChainBuilder}; this class stays as the leave context's seam so callers
 * and tests keep one stable entry point.
 */
class LeaveApprovalRouting
{
    public function __construct(private readonly ChainBuilder $chains) {}

    /**
     * @param  array<string, mixed>  $payload  condition inputs (`days`)
     * @return list<ApproverSpec>
     */
    public function stepsFor(Employee $employee, string $domain = 'leave', array $payload = []): array
    {
        return $this->chains->stepsFor($domain, $employee, $payload);
    }
}
