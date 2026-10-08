<?php

namespace App\Services\Hrms\Payroll;

use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\PayslipTemplate;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Payroll/HRMS — one payslip, rendered to printable HTML.
 *
 * No PDF library is vendored, so the download is a self-contained HTML file
 * the browser prints to PDF. The tenant's default template customises it
 * (`header_html`, `footer_html`, `show_employer_contributions`); absent keys
 * fall back to the built-in layout, so a null content still renders. Every
 * dynamic value is escaped — the template's own HTML is admin-authored and
 * the only raw output.
 */
class PayslipRenderer
{
    public function __construct(private readonly TenantContext $context) {}

    public function render(Payslip $payslip): string
    {
        $payslip->loadMissing(['employee:id,employee_code,name', 'adjustments', 'run']);

        $template = PayslipTemplate::query()->active()->default()->first()
            ?? PayslipTemplate::query()->active()->orderBy('id')->first();

        $content = $template?->content ?? [];
        $showEmployer = (bool) ($content['show_employer_contributions'] ?? true);

        $rows = fn (?array $lines): string => collect($lines ?? [])
            ->map(fn ($line): string => '<tr><td>'.e((string) ($line['name'] ?? $line['code'] ?? '')).'</td><td class="n">'.e((string) ($line['monthly'] ?? '0.00')).'</td></tr>')
            ->implode('');

        $adjustments = $payslip->adjustments->map(fn ($row): string => '<tr><td>'.e((string) $row->label).'</td><td class="n">'.e(($row->kind === 'deduction' ? '−' : '').(string) $row->amount).'</td></tr>')->implode('');

        $leave = $payslip->leave_days ?? [];
        $period = Carbon::create((int) $payslip->run->period_year, (int) $payslip->run->period_month, 1)->format('F Y');

        return '<!doctype html><html><head><meta charset="utf-8"><title>Payslip '.e($period).'</title><style>'
            .'body{font-family:sans-serif;color:#111;max-width:720px;margin:32px auto;padding:0 16px}'
            .'table{width:100%;border-collapse:collapse;margin:12px 0}td,th{border:1px solid #999;padding:6px 8px;text-align:left}'
            .'.n{text-align:right}h1{font-size:20px}h2{font-size:15px;margin-top:24px}.muted{color:#555;font-size:12px}</style></head><body>'
            .($content['header_html'] ?? '<h1>Payslip — '.e($period).'</h1><p class="muted">'.e($this->companyName()).'</p>')
            .'<p><strong>'.e($payslip->employee?->displayName() ?? '—').'</strong> <span class="muted">'.e((string) ($payslip->employee?->employee_code ?? '')).'</span><br>'
            .'<span class="muted">Pay date '.e($payslip->run->pay_date->toDateString()).' · Working '.e((string) $payslip->working_days).' · Paid '.e((string) $payslip->paid_days).' · LOP '.e((string) $payslip->lop_days).'</span></p>'
            .'<h2>Earnings</h2><table>'.$rows($payslip->earnings).$adjustments.'</table>'
            .'<h2>Deductions</h2><table>'.$rows($payslip->deductions).'</table>'
            .($showEmployer ? '<h2>Employer contributions</h2><table>'.$rows($payslip->employer_contributions).'</table>' : '')
            .'<h2>Totals</h2><table>'
            .'<tr><td>Gross pay</td><td class="n">'.e((string) $payslip->gross_pay).'</td></tr>'
            .'<tr><td>Total deductions</td><td class="n">'.e((string) $payslip->total_deductions).'</td></tr>'
            .'<tr><td><strong>Net pay</strong></td><td class="n"><strong>'.e((string) $payslip->net_pay).'</strong></td></tr></table>'
            .'<p class="muted">Unpaid leave '.e((string) ($leave['unpaid_days'] ?? 0)).' day(s) · Overtime '.e((string) $payslip->ot_minutes).' min · Status '.e($payslip->status->value).'</p>'
            .($content['footer_html'] ?? '<p class="muted">System-generated payslip.</p>')
            .'</body></html>';
    }

    public function filename(Payslip $payslip): string
    {
        $payslip->loadMissing('run');

        $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payslip->employee?->employee_code ?? $payslip->employee_id)) ?: 'payslip';

        return "payslip-{$payslip->run->period_year}-".str_pad((string) $payslip->run->period_month, 2, '0', STR_PAD_LEFT)."-{$code}.html";
    }

    private function companyName(): string
    {
        $tenantId = $this->context->currentId();

        if ($tenantId === null) {
            return '';
        }

        return (string) (Tenant::find($tenantId)?->name ?? '');
    }
}
