<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExportRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Services\Export\ExportCsvWriter;
use App\Services\Export\HrmsExportCategories;
use App\Support\Tms\TmsSchema;
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

    private readonly ExportCsvWriter $writer;

    private readonly HrmsExportCategories $hrms;

    public function __construct(
        ?ExportCsvWriter $writer = null,
        ?HrmsExportCategories $hrms = null,
    ) {
        $this->writer = $writer ?? new ExportCsvWriter;
        $this->hrms = $hrms ?? new HrmsExportCategories($this->writer);
    }

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
                'employees' => $this->hrms->addEmployees($zip, self::CHUNK),
                'leave_requests' => $this->hrms->addLeaveRequests($zip, self::CHUNK),
                'payslips' => $this->hrms->addPayslips($zip, self::CHUNK),
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
        $this->writer->write($zip, $filename, $headers, $rows);
    }

    // ---------------------------------------------------------------- categories

    private function addWorkspaces(ZipArchive $zip): void
    {
        $headers = ['id', 'name', 'slug', 'status', 'members_count', 'projects_count', 'created_at'];
        $rows = [];

        // TMS-less tenants hold no workspaces table: an empty CSV, not a 500
        // (the HRMS-categories precedent below).
        if (TmsSchema::present()) {
            Workspace::withCount(['members', 'projects'])->chunk(self::CHUNK, function ($batch) use (&$rows) {
                foreach ($batch as $w) {
                    $rows[] = [
                        $w->id, $w->name, $w->slug, $w->status,
                        $w->members_count, $w->projects_count,
                        $w->created_at?->toIso8601String(),
                    ];
                }
            });
        }

        $this->writeCsv($zip, 'workspaces.csv', $headers, $rows);
    }

    private function addProjects(ZipArchive $zip): void
    {
        $headers = ['id', 'key', 'name', 'workspace_id', 'status', 'tasks_count', 'created_at'];
        $rows = [];

        if (TmsSchema::present()) {
            Project::withCount('tasks')->chunk(self::CHUNK, function ($batch) use (&$rows) {
                foreach ($batch as $p) {
                    $rows[] = [
                        $p->id, $p->key, $p->name, $p->workspace_id,
                        $p->status, $p->tasks_count,
                        $p->created_at?->toIso8601String(),
                    ];
                }
            });
        }

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

        if (TmsSchema::present()) {
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
        }

        $this->writeCsv($zip, 'tasks.csv', $headers, $rows);
    }

    private function addWorkLogs(ZipArchive $zip): void
    {
        $headers = ['id', 'task_id', 'user_id', 'minutes', 'started_at', 'note', 'created_at'];
        $rows = [];

        if (TmsSchema::present()) {
            WorkLog::chunk(self::CHUNK, function ($batch) use (&$rows) {
                foreach ($batch as $wl) {
                    $rows[] = [
                        $wl->id, $wl->task_id, $wl->user_id, $wl->minutes,
                        $wl->started_at?->toIso8601String(), $wl->note,
                        $wl->created_at?->toIso8601String(),
                    ];
                }
            });
        }

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
}
