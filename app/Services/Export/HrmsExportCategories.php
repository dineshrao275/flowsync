<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Support\Hrms\HrmsSchema;
use ZipArchive;

class HrmsExportCategories
{
    public function __construct(
        private readonly ExportCsvWriter $writer,
    ) {}

    /**
     * HRMS employees — only present when the model class exists (HRMS module
     * provisioned). We check with class_exists so this category degrades
     * gracefully to an empty CSV on Starter/Pro tenants.
     */
    public function addEmployees(ZipArchive $zip, int $chunk): void
    {
        $headers = [
            'id', 'employee_code', 'user_id', 'status',
            'joining_date', 'exit_date', 'created_at',
        ];
        $rows = [];

        $modelClass = 'App\\Models\\Hrms\\Employee\\Employee';
        if (class_exists($modelClass) && HrmsSchema::present()) {
            $modelClass::chunk($chunk, function ($batch) use (&$rows) {
                foreach ($batch as $e) {
                    $rows[] = [
                        $e->id, $e->employee_code, $e->user_id, $e->status,
                        $e->joining_date?->toDateString(), $e->exit_date?->toDateString(),
                        $e->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writer->write($zip, 'employees.csv', $headers, $rows);
    }

    public function addLeaveRequests(ZipArchive $zip, int $chunk): void
    {
        $headers = [
            'id', 'employee_id', 'leave_type_id', 'status',
            'from_date', 'to_date', 'days', 'created_at',
        ];
        $rows = [];

        $modelClass = 'App\\Models\\Hrms\\Leave\\LeaveRequest';
        if (class_exists($modelClass)) {
            $modelClass::chunk($chunk, function ($batch) use (&$rows) {
                foreach ($batch as $lr) {
                    $rows[] = [
                        $lr->id, $lr->employee_id, $lr->leave_type_id, $lr->status,
                        $lr->from_date?->toDateString(), $lr->to_date?->toDateString(),
                        $lr->days, $lr->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writer->write($zip, 'leave_requests.csv', $headers, $rows);
    }

    public function addPayslips(ZipArchive $zip, int $chunk): void
    {
        // Payslips contain sensitive financial data — export headers only (no
        // salary values). A future permission expansion can enable the values
        // behind an explicit consent gate.
        $headers = ['id', 'employee_id', 'period_month', 'period_year', 'status', 'created_at'];
        $rows = [];

        $modelClass = 'App\\Models\\Hrms\\Payroll\\Payslip';
        if (class_exists($modelClass)) {
            $modelClass::chunk($chunk, function ($batch) use (&$rows) {
                foreach ($batch as $ps) {
                    $rows[] = [
                        $ps->id, $ps->employee_id, $ps->period_month, $ps->period_year,
                        $ps->status, $ps->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writer->write($zip, 'payslips.csv', $headers, $rows);
    }
}
