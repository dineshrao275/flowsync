<?php

namespace App\Http\Controllers\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Employee\EmployeeBulkStatusRequest;
use App\Http\Requests\Hrms\Employee\EmployeeImportRequest;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Employee\Bulk\EmployeeBulkStatus;
use App\Services\Hrms\Employee\Bulk\EmployeeImporter;
use App\Support\Hrms\CsvTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee/HRMS — bulk hire from CSV (preview → commit) and bulk status change.
 *
 * Thin: authorize, delegate, present. The import is a create for every row, so
 * it asks the same `create` policy as the single-hire endpoint; the bulk
 * status change asks `changeStatus` per person inside the service.
 */
class EmployeeBulkController extends Controller
{
    public function __construct(
        private readonly EmployeeImporter $importer,
        private readonly EmployeeBulkStatus $bulkStatus,
    ) {}

    public function sample(): StreamedResponse
    {
        $this->authorize('create', Employee::class);

        return response()->streamDownload(
            fn () => print (CsvTable::render(EmployeeImporter::SAMPLE)),
            'employee-import-sample.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function preview(EmployeeImportRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $report = $this->importer->validate($this->importer->parse($request->file('file')));
        $report['rows'] = array_map(fn (array $r): array => ['line' => $r['line'], 'name' => $r['name'], 'errors' => $r['errors']], $report['rows']);

        return response()->json($report);
    }

    public function import(EmployeeImportRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $report = $this->importer->validate($this->importer->parse($request->file('file')));
        $result = $this->importer->commit($report['rows'], $request->boolean('skip_invalid'), $request->user());

        return response()->json(['message' => "{$result['created']} employee(s) imported.", ...$result], Response::HTTP_CREATED);
    }

    public function status(EmployeeBulkStatusRequest $request): JsonResponse
    {
        // Same grant as the per-person endpoint; the service re-checks each record.
        $this->authorize('create', Employee::class);

        $data = $request->validated();

        $result = $this->bulkStatus->run(
            $data['employee_ids'],
            EmployeeStatus::from($data['to']),
            array_intersect_key($data, array_flip(['effective_date', 'reason', 'note'])),
            (bool) ($data['dry_run'] ?? false),
            $request->user(),
        );

        return response()->json($result);
    }
}
