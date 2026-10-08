<?php

namespace App\Http\Controllers\Hrms;

use App\Enums\Hrms\DataAccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\AnalyticsExportRequest;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\User;
use App\Services\Hrms\Analytics\AnalyticsCsvExport;
use App\Services\Hrms\HrmsAnalyticsService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics/HRMS — downloading one dashboard domain as CSV.
 *
 * Thin: the request validates the domain and filters, the gate answers to
 * tenant admins only (bulk pulls are an admin affordance, not a reader
 * one — viewing a chart never implied taking the dataset home), and the
 * readers return the same tiered arrays the tabs render, so the file and
 * the chart cannot disagree. Every export writes an
 * `accessed(..., Export)` row with the exported field names — a bulk pull
 * outward, never a silent one (the P19.3 rule, applied at birth).
 */
class HrmsAnalyticsExportController extends Controller
{
    /**
     * The aggregate subject per domain. Exports are tenant-wide slices, so
     * the record id is 0 (the payroll-reading precedent) and the fields
     * column carries the exported top-level keys.
     *
     * @var array<string, class-string>
     */
    private const MODELS = [
        'attendance' => AttendanceDay::class,
        'leave' => LeaveRequest::class,
        'lifecycle' => OnboardingCase::class,
        'performance' => PerformanceGoal::class,
        'payroll' => Payslip::class,
        'documents' => EmployeeDocument::class,
        'assets' => Asset::class,
    ];

    public function __construct(
        private readonly HrmsAnalyticsService $analytics,
        private readonly AnalyticsCsvExport $csv,
        private readonly HrmsAuditLogger $audit,
    ) {}

    public function export(AnalyticsExportRequest $request): StreamedResponse
    {
        $domain = $request->validated()['domain'];

        Gate::authorize('permission', 'workspaces.manage');

        $data = $this->read($domain, $request->filters(), $request->user());

        $model = self::MODELS[$domain];
        $this->audit->accessed(
            (new $model)->getMorphClass(),
            0,
            DataAccessAction::Export,
            array_keys($data),
            $request->user(),
            $request->ip(),
        );

        ['headers' => $headers, 'rows' => $rows] = $this->csv->forDomain($domain, $data);

        $filename = "hrms-analytics-{$domain}-".now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The same tiered reads the tabs render — liability rides payroll
     * runners, ratings ride talent viewers, payroll logs its actor inside
     * the reader (the P18.3 tiers, not a second copy of them).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function read(string $domain, array $filters, User $user): array
    {
        return match ($domain) {
            'attendance' => $this->analytics->attendance($filters),
            'leave' => $this->analytics->leave($filters, true, $user->hasPermission('hrms.payroll.run')),
            'lifecycle' => $this->analytics->lifecycle($filters),
            'performance' => $this->analytics->performance($filters, $user->hasPermission('hrms.talent.view')),
            'payroll' => $this->analytics->payroll($filters, $user),
            'documents' => $this->analytics->documents(
                $filters,
                true,
                $user->hasPermission('hrms.documents.view_sensitive'),
            ),
            'assets' => $this->analytics->assets($filters),
        };
    }
}
