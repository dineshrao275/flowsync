<?php

namespace App\Services\Hrms\Payroll;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll/HRMS — serving one rendered payslip from a signed link.
 *
 * The DocumentDownload shape, with one stricter rule: every payslip is
 * sensitive, so an anonymous holder of a valid signature gets nothing — the
 * reader named in the URL must satisfy the payslip policy (all-viewer, or
 * self with the view permission) right now. The download always writes its
 * access row, like every other payslip read.
 */
class PayslipDownload
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly TenantContext $context,
        private readonly TenantDatabaseManager $tenants,
        private readonly PayslipRenderer $renderer,
    ) {}

    /**
     * A temporary signed URL for one payslip, bound to its reader.
     *
     * The reader rides inside the signature with the central tenant id, so a
     * session-free download is attributable to the account that minted it
     * rather than to "whoever held the link".
     */
    public function url(Payslip $payslip, ?User $reader = null): string
    {
        $parameters = ['payslip' => $payslip->id, 'tenant' => $this->tenantId()];

        if ($reader !== null) {
            $parameters['actor'] = $reader->id;
        }

        return url()->temporarySignedRoute('hrms.payslips.download', now()->addHours(1), $parameters);
    }

    /**
     * Stream one rendered payslip for a signed request, with no session and
     * no tenant context: the central tenant id and the reader arrive inside
     * the signature, and the record is resolved inside the tenant connection.
     */
    public function stream(int $payslipId, int $tenantId, ?int $actorUserId, ?string $ipAddress): StreamedResponse
    {
        $tenant = Tenant::find($tenantId);
        abort_if($tenant === null, 404);

        return $this->tenants->using($tenant, function () use ($payslipId, $actorUserId, $ipAddress): StreamedResponse {
            $payslip = Payslip::query()->with('employee:id,employee_code,name,user_id')->find($payslipId);
            abort_if($payslip === null, 404);

            $reader = $actorUserId === null ? null : User::find($actorUserId);
            $this->requireDownloadReader($payslip, $reader);

            $this->audit->accessed(
                (new Payslip)->getMorphClass(),
                $payslip->id,
                DataAccessAction::Download,
                ['rendered_payslip'],
                $reader,
                $ipAddress,
            );

            $pdf = $this->renderer->renderPdf($payslip);
            $filename = $this->renderer->filename($payslip);

            return response()->streamDownload(function () use ($pdf): void {
                echo $pdf;
            }, $filename, ['Content-Type' => 'application/pdf']);
        });
    }

    private function tenantId(): int
    {
        $tenantId = $this->context->currentId();

        if ($tenantId === null) {
            throw ValidationException::withMessages(['form' => 'Payslips need an active tenant before a link can be minted.']);
        }

        return $tenantId;
    }

    /**
     * The download permission, after the record is resolved: the named
     * reader must satisfy the same rule as the JSON show — an all-viewer, or
     * the person in the payslip holding the view permission. Nobody anonymous.
     */
    private function requireDownloadReader(Payslip $payslip, ?User $reader): void
    {
        if ($reader === null) {
            abort(403, 'This download needs a signed reader.');
        }

        $reader->loadMissing('roles.permissions');

        if ($reader->hasPermission('hrms.payroll.view_all')) {
            return;
        }

        $employee = $payslip->employee ?? Employee::find($payslip->employee_id);
        $isSelf = $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $reader->id;

        if (! $isSelf || ! $reader->hasPermission('hrms.payroll.view')) {
            abort(403, 'This payslip is not yours to download.');
        }
    }
}
