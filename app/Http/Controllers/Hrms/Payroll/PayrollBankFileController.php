<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Enums\Hrms\DataAccessAction;
use App\Enums\Hrms\PayrollRunStatus;
use App\Http\Controllers\Controller;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Statutory\StatutoryProfile;
use App\Services\HrmsAuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bank payout file for a payroll run: one row per payslip with the employee's bank details and net pay.
 * It reveals decrypted account numbers, so it needs the runner permission, is throttled, only exists for a
 * run that is approved or later, skips anyone with no bank details (listed in a header comment-free summary
 * via the `X-Missing-Bank` count) and writes a data-access row.
 */
class PayrollBankFileController extends Controller
{
    private const TEMPLATES = [
        'generic' => ['Employee code', 'Name', 'Bank', 'Account number', 'IFSC', 'Net pay', 'Narration'],
        'neft' => ['Beneficiary name', 'Beneficiary account', 'IFSC', 'Amount', 'Remarks'],
    ];

    public function __construct(private readonly HrmsAuditLogger $audit) {}

    public function __invoke(Request $request, PayrollRun $run): StreamedResponse
    {
        $this->authorize('markPaid', $run);
        $format = $request->validate(['format' => ['nullable', 'in:'.implode(',', array_keys(self::TEMPLATES))]])['format'] ?? 'generic';

        abort_unless(in_array($run->status, [PayrollRunStatus::Approved, PayrollRunStatus::Processing, PayrollRunStatus::Paid, PayrollRunStatus::Locked], true), 422, __('The payout file is available once the run is approved.'));

        $slips = $run->payslips()->with('employee:id,name,employee_code')->where('net_pay', '>', 0)->get();
        $profiles = StatutoryProfile::query()->whereIn('employee_id', $slips->pluck('employee_id'))->get()->keyBy('employee_id');

        $missing = 0;
        $rows = [];
        foreach ($slips as $slip) {
            $p = $profiles[$slip->employee_id] ?? null;
            if ($p === null || ! $p->bank_account_encrypted || ! $p->bank_ifsc) {
                $missing++;

                continue;
            }
            $narration = sprintf('Salary %04d-%02d', $run->period_year, $run->period_month);
            $rows[] = $format === 'neft'
                ? [$slip->employee->name, $p->bank_account_encrypted, $p->bank_ifsc, $slip->net_pay, $narration]
                : [$slip->employee->employee_code, $slip->employee->name, $p->bank_name, $p->bank_account_encrypted, $p->bank_ifsc, $slip->net_pay, $narration];
        }

        $this->audit->accessed($run->getMorphClass(), $run->id, DataAccessAction::Export, ['bank_account', 'net_pay'], $request->user(), $request->ip());

        return response()->streamDownload(function () use ($format, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::TEMPLATES[$format]);
            foreach ($rows as $row) {
                // A cell that opens with a formula character is prefixed so a spreadsheet cannot execute it.
                fputcsv($out, array_map(fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) ? "'".$c : $c, $row));
            }
            fclose($out);
        }, "payout-run-{$run->id}-{$format}.csv", ['Content-Type' => 'text/csv', 'X-Missing-Bank' => (string) $missing]);
    }
}
