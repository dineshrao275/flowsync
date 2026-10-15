<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuditsTenantAdminActions;
use App\Models\Role;
use App\Services\Users\UserImporter;
use App\Support\GrantCeiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tenant-admin CSV user import: sample file, dry-run preview, then commit. */
class UserImportController extends Controller
{
    use AuditsTenantAdminActions;

    public function __construct(private readonly UserImporter $importer) {}

    public function sample(): Response
    {
        $csv = collect(UserImporter::SAMPLE)
            ->map(fn (array $row) => implode(',', array_map(fn ($c) => str_contains($c, ',') ? '"'.$c.'"' : $c, $row)))
            ->implode("\r\n")."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-import-sample.csv"',
        ]);
    }

    /** Validate every row without creating anything. */
    public function preview(Request $request): JsonResponse
    {
        $rows = $this->validated($request);

        return response()->json($this->report($rows) + ['available_roles' => $this->assignableRoles($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['skip_invalid' => ['sometimes', 'boolean']]);
        $rows = $this->validated($request);
        $ok = array_values(array_filter($rows, fn ($r) => $r['errors'] === []));
        $skip = $request->boolean('skip_invalid');

        if (count($ok) !== count($rows) && ! $skip) {
            return response()->json([
                'message' => 'Some rows have errors. Fix the file, or import only the valid rows.',
                ...$this->report($rows),
            ], 422);
        }
        if ($ok === []) {
            return response()->json(['message' => 'There are no valid rows to import.', ...$this->report($rows)], 422);
        }

        $created = DB::transaction(fn () => $this->importer->import($ok));

        $this->auditTenantAdmin($request, 'users.imported', 'users', null, null, [
            'imported' => count($created),
            'skipped' => count($rows) - count($ok),
            'emails' => array_column($created, 'email'),
        ]);

        return response()->json([
            'message' => count($created).' user'.(count($created) === 1 ? '' : 's').' imported.',
            'created' => $created,
            ...$this->report($rows),
        ], 201);
    }

    /** @return array<int, array<string, mixed>> */
    private function validated(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);

        return $this->importer->validate($request->user(), $this->importer->parse($request->file('file')));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function report(array $rows): array
    {
        $valid = count(array_filter($rows, fn ($r) => $r['errors'] === []));

        return [
            'summary' => ['total' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid],
            'rows' => array_map(fn ($r) => [
                'line' => $r['line'], 'name' => $r['name'], 'email' => $r['email'],
                'roles' => $r['roles'], 'errors' => $r['errors'],
            ], $rows),
        ];
    }

    /** Roles this admin may actually hand out, so the UI can show what the CSV may contain. */
    private function assignableRoles(Request $request): array
    {
        return Role::with('permissions:id,slug')->orderBy('name')->get()
            ->filter(function (Role $role) use ($request): bool {
                try {
                    GrantCeiling::assertCanAssignRoles($request->user(), collect([$role->id]), collect());

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            })
            ->map(fn (Role $r) => ['slug' => $r->slug, 'name' => $r->name])->values()->all();
    }
}
