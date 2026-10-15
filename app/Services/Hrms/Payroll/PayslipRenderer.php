<?php

namespace App\Services\Hrms\Payroll;

use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\PayslipTemplate;
use App\Models\Tenant;
use App\Support\Pdf\SimplePdf;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Payroll/HRMS — one payslip, rendered to printable HTML.
 *
 * The download is a real PDF (`renderPdf`, built with the dependency-free
 * SimplePdf writer); `render` keeps the printable HTML form for anything that
 * wants markup. The tenant's default template customises the HTML
 * (`header_html`, `footer_html`) and both forms honour
 * `show_employer_contributions`; absent keys fall back to the built-in
 * layout, so a null content still renders. Every dynamic value is escaped —
 * the template's own HTML is admin-authored and the only raw output. The PDF
 * is text-only, so the template's HTML header/footer are not carried over.
 */
class PayslipRenderer
{
    public function __construct(private readonly TenantContext $context) {}

    public function render(Payslip $payslip): string
    {
        $payslip->loadMissing(['employee:id,employee_code,name', 'adjustments', 'run']);

        $content = $this->templateContent();
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

    /** The payslip as PDF bytes: same figures as `render`, laid out as text and right-aligned amounts. */
    public function renderPdf(Payslip $payslip): string
    {
        $payslip->loadMissing(['employee:id,employee_code,name', 'adjustments', 'run']);

        $right = SimplePdf::WIDTH - SimplePdf::MARGIN;
        $period = Carbon::create((int) $payslip->run->period_year, (int) $payslip->run->period_month, 1)->format('F Y');
        $leave = $payslip->leave_days ?? [];
        $amount = fn (mixed $value): string => (string) ($value ?? '0.00');
        $section = function (SimplePdf $pdf, string $title, iterable $rows) use ($right, $amount): void {
            $pdf->gap(8)->line($title, 11, true)->rule();
            foreach ($rows as [$label, $value]) {
                $pdf->row([[(string) $label, SimplePdf::MARGIN], [$amount($value), $right, true]]);
            }
        };
        $lines = fn (?array $items): array => collect($items ?? [])->map(fn ($line): array => [$line['name'] ?? $line['code'] ?? '', $line['monthly'] ?? '0.00'])->all();

        $pdf = (new SimplePdf)
            ->line('Payslip - '.$period, 16, true)
            ->line($this->companyName(), 9)
            ->gap(6)
            ->line(($payslip->employee?->displayName() ?? '-').'  '.(string) ($payslip->employee?->employee_code ?? ''), 11, true)
            ->line('Pay date '.$payslip->run->pay_date->toDateString().'   Working '.$payslip->working_days.'   Paid '.$payslip->paid_days.'   LOP '.$payslip->lop_days, 9);

        $earnings = array_merge($lines($payslip->earnings), $payslip->adjustments->map(fn ($row): array => [(string) $row->label, ($row->kind === 'deduction' ? '-' : '').(string) $row->amount])->all());
        $section($pdf, 'Earnings', $earnings);
        $section($pdf, 'Deductions', $lines($payslip->deductions));

        if ((bool) ($this->templateContent()['show_employer_contributions'] ?? true)) {
            $section($pdf, 'Employer contributions', $lines($payslip->employer_contributions));
        }

        $section($pdf, 'Totals', [['Gross pay', $payslip->gross_pay], ['Total deductions', $payslip->total_deductions]]);
        $pdf->row([['Net pay', SimplePdf::MARGIN], [$amount($payslip->net_pay), $right, true]], 12, true)
            ->gap(8)
            ->line('Unpaid leave '.($leave['unpaid_days'] ?? 0).' day(s)   Overtime '.$payslip->ot_minutes.' min   Status '.$payslip->status->value, 9)
            ->line('System-generated payslip.', 9);

        return $pdf->output();
    }

    /** The default (else first active) template's content, `[]` when the tenant has none. */
    private function templateContent(): array
    {
        $template = PayslipTemplate::query()->active()->default()->first()
            ?? PayslipTemplate::query()->active()->orderBy('id')->first();

        return $template?->content ?? [];
    }

    public function filename(Payslip $payslip): string
    {
        $payslip->loadMissing('run');

        $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payslip->employee?->employee_code ?? $payslip->employee_id)) ?: 'payslip';

        return "payslip-{$payslip->run->period_year}-".str_pad((string) $payslip->run->period_month, 2, '0', STR_PAD_LEFT)."-{$code}.pdf";
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
