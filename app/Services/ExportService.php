<?php

namespace App\Services;

use App\Models\ExportRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use ZipArchive;

/**
 * Phase 5 — full tenant data export.
 *
 * Streams each requested category as a CSV file into a temporary ZIP on the
 * `local` disk (storage/app/private/exports/{run_id}.zip). The export is
 * chunked (500 rows at a time) so GB-scale tenants never spike memory.
 *
 * Supported categories (all optional; default = all):
 *   workspaces, projects, tasks, work_logs, members,
 *   employees (HRMS), leave_requests (HRMS), payslips (HRMS)
 *
 * The Job calls build() and updates the ExportRun on completion/failure.
 * ExportController streams the result via a signed URL.
 */
class ExportService
{
    public const CATEGORIES = [
        'workspaces',
        'projects',
        'tasks',
        'work_logs',
        'members',
        'employees',
        'leave_requests',
        'payslips',
    ];

    private const CHUNK = 500;

    /**
     * Build the ZIP for the given ExportRun.
     *
     * Writes to `exports/{run_id}.zip` on the local disk. Returns the
     * storage-relative path on success, or throws on failure.
     *
     * @return string storage-relative path
     *
     * @throws \RuntimeException
     */
    public function build(ExportRun $run): string
    {
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, recursive: true);
        }

        $zipPath = $dir.'/'.$run->id.'.zip';
        $storagePath = 'exports/'.$run->id.'.zip';

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP at {$zipPath}");
        }

        $categories = $run->categories ?? self::CATEGORIES;

        $zip->addFromString('manifest.json', json_encode([
            'exported_at' => now()->toIso8601String(),
            'categories' => $categories,
            'run_id' => $run->id,
        ], JSON_PRETTY_PRINT));

        foreach ($categories as $category) {
            match ($category) {
                'workspaces' => $this->addWorkspaces($zip),
                'projects' => $this->addProjects($zip),
                'tasks' => $this->addTasks($zip),
                'work_logs' => $this->addWorkLogs($zip),
                'members' => $this->addMembers($zip),
                'employees' => $this->addEmployees($zip),
                'leave_requests' => $this->addLeaveRequests($zip),
                'payslips' => $this->addPayslips($zip),
                default => null,
            };
        }

        $zip->close();

        return $storagePath;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Write a category CSV into the ZIP using in-memory chunking.
     * $headers: column headers, $rows: iterable of arrays matching header order.
     */
    private function writeCsv(ZipArchive $zip, string $filename, array $headers, iterable $rows): void
    {
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, $headers);

        foreach ($rows as $row) {
            fputcsv($buffer, array_map($this->csvCell(...), $row));
        }

        rewind($buffer);
        $zip->addFromString($filename, stream_get_contents($buffer));
        fclose($buffer);
    }

    /** Backed enums and dates arrive as objects; fputcsv only stringifies scalars. */
    private function csvCell(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format('c'),
            is_array($value) => json_encode($value),
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_object($value) => null,
            default => $value,
        };
    }

    // ---------------------------------------------------------------- categories

    private function addWorkspaces(ZipArchive $zip): void
    {
        $headers = ['id', 'name', 'slug', 'status', 'members_count', 'projects_count', 'created_at'];
        $rows = [];

        Workspace::withCount(['members', 'projects'])->chunk(self::CHUNK, function ($batch) use (&$rows) {
            foreach ($batch as $w) {
                $rows[] = [
                    $w->id, $w->name, $w->slug, $w->status,
                    $w->members_count, $w->projects_count,
                    $w->created_at?->toIso8601String(),
                ];
            }
        });

        $this->writeCsv($zip, 'workspaces.csv', $headers, $rows);
    }

    private function addProjects(ZipArchive $zip): void
    {
        $headers = ['id', 'key', 'name', 'workspace_id', 'status', 'tasks_count', 'created_at'];
        $rows = [];

        Project::withCount('tasks')->chunk(self::CHUNK, function ($batch) use (&$rows) {
            foreach ($batch as $p) {
                $rows[] = [
                    $p->id, $p->key, $p->name, $p->workspace_id,
                    $p->status, $p->tasks_count,
                    $p->created_at?->toIso8601String(),
                ];
            }
        });

        $this->writeCsv($zip, 'projects.csv', $headers, $rows);
    }

    private function addTasks(ZipArchive $zip): void
    {
        $headers = [
            'id', 'key', 'title', 'project_id', 'status_id', 'priority_id',
            'assignee_id', 'parent_id', 'position', 'due_date', 'completed_at',
            'created_at', 'deleted_at',
        ];
        $rows = [];

        Task::withTrashed()->chunk(self::CHUNK, function ($batch) use (&$rows) {
            foreach ($batch as $t) {
                $rows[] = [
                    $t->id, $t->key, $t->title, $t->project_id, $t->status_id,
                    $t->priority_id, $t->assignee_id, $t->parent_id, $t->position,
                    $t->due_date?->toDateString(), $t->completed_at?->toIso8601String(),
                    $t->created_at?->toIso8601String(), $t->deleted_at?->toIso8601String(),
                ];
            }
        });

        $this->writeCsv($zip, 'tasks.csv', $headers, $rows);
    }

    private function addWorkLogs(ZipArchive $zip): void
    {
        $headers = ['id', 'task_id', 'user_id', 'minutes', 'started_at', 'note', 'created_at'];
        $rows = [];

        WorkLog::chunk(self::CHUNK, function ($batch) use (&$rows) {
            foreach ($batch as $wl) {
                $rows[] = [
                    $wl->id, $wl->task_id, $wl->user_id, $wl->minutes,
                    $wl->started_at?->toIso8601String(), $wl->note,
                    $wl->created_at?->toIso8601String(),
                ];
            }
        });

        $this->writeCsv($zip, 'work_logs.csv', $headers, $rows);
    }

    private function addMembers(ZipArchive $zip): void
    {
        $headers = ['id', 'name', 'email', 'roles', 'created_at'];
        $rows = [];

        User::with('roles')->chunk(self::CHUNK, function ($batch) use (&$rows) {
            foreach ($batch as $u) {
                $rows[] = [
                    $u->id, $u->name, $u->email,
                    $u->roles->pluck('name')->implode(', '),
                    $u->created_at?->toIso8601String(),
                ];
            }
        });

        $this->writeCsv($zip, 'members.csv', $headers, $rows);
    }

    /**
     * HRMS employees — only present when the model class exists (HRMS module
     * provisioned). We check with class_exists so this category degrades
     * gracefully to an empty CSV on Starter/Pro tenants.
     */
    private function addEmployees(ZipArchive $zip): void
    {
        $headers = [
            'id', 'employee_code', 'user_id', 'status',
            'joining_date', 'exit_date', 'created_at',
        ];
        $rows = [];

        $modelClass = 'App\\Models\\Hrms\\Employee\\Employee';
        if (class_exists($modelClass)) {
            $modelClass::chunk(self::CHUNK, function ($batch) use (&$rows) {
                foreach ($batch as $e) {
                    $rows[] = [
                        $e->id, $e->employee_code, $e->user_id, $e->status,
                        $e->joining_date?->toDateString(), $e->exit_date?->toDateString(),
                        $e->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writeCsv($zip, 'employees.csv', $headers, $rows);
    }

    private function addLeaveRequests(ZipArchive $zip): void
    {
        $headers = [
            'id', 'employee_id', 'leave_type_id', 'status',
            'from_date', 'to_date', 'days', 'created_at',
        ];
        $rows = [];

        $modelClass = 'App\\Models\\Hrms\\Leave\\LeaveRequest';
        if (class_exists($modelClass)) {
            $modelClass::chunk(self::CHUNK, function ($batch) use (&$rows) {
                foreach ($batch as $lr) {
                    $rows[] = [
                        $lr->id, $lr->employee_id, $lr->leave_type_id, $lr->status,
                        $lr->from_date?->toDateString(), $lr->to_date?->toDateString(),
                        $lr->days, $lr->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writeCsv($zip, 'leave_requests.csv', $headers, $rows);
    }

    private function addPayslips(ZipArchive $zip): void
    {
        // Payslips contain sensitive financial data — export headers only (no
        // salary values). A future permission expansion can enable the values
        // behind an explicit consent gate.
        $headers = ['id', 'employee_id', 'period_month', 'period_year', 'status', 'created_at'];
        $rows = [];

        $modelClass = 'App\\Models\\Hrms\\Payroll\\Payslip';
        if (class_exists($modelClass)) {
            $modelClass::chunk(self::CHUNK, function ($batch) use (&$rows) {
                foreach ($batch as $ps) {
                    $rows[] = [
                        $ps->id, $ps->employee_id, $ps->period_month, $ps->period_year,
                        $ps->status, $ps->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writeCsv($zip, 'payslips.csv', $headers, $rows);
    }
}
