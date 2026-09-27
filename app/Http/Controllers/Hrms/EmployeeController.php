<?php

namespace App\Http\Controllers\Hrms;

use App\Enums\Hrms\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\EmployeeIndexRequest;
use App\Http\Requests\Hrms\EmployeeStatusChangeRequest;
use App\Http\Requests\Hrms\EmployeeStoreRequest;
use App\Http\Requests\Hrms\EmployeeTerminateRequest;
use App\Http\Requests\Hrms\EmployeeUpdateRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Policies\Hrms\Employee\EmployeePolicy;
use App\Services\Hrms\Employee\EmployeePresenter;
use App\Services\Hrms\Employee\EmployeeService;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee/HRMS — the HTTP surface for employment records.
 *
 * Thin by design (D2.12/D2.16): it authorizes, it hands the payload to
 * {@see EmployeeService}, and it shapes the response. No rule about an employee
 * lives here — a controller-level `if` is a rule the service layer cannot see,
 * and therefore cannot enforce for the console command, the seeder or the
 * queue worker that will eventually need the same behaviour.
 *
 * Note the route group: `permission:hrms.view` gates the surface, and
 * {@see EmployeePolicy} gates each record. The
 * per-object check is not redundant with the route check — "can reach the
 * employee module" and "can read this person's record" are different questions,
 * and the second is the one with a self-service answer.
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EmployeePresenter $presenter,
    ) {}

    /**
     * The directory.
     */
    public function index(EmployeeIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->filters();
        $page = $this->employees->list($filters);

        $rows = $page->getCollection()
            ->map(fn (Employee $employee) => $this->present($employee, $request))
            ->all();

        return response()->json([
            'employees' => $rows,
            'pagination' => $this->pagination($page),
            'filters' => $this->employees->filterOptions($filters),
            // Not a per-employee role as in the task domain — the caller's own
            // tenant role, so a client can decide which buttons to draw without
            // a second round trip.
            'my_role' => $request->user()->roles->pluck('slug')->first(),
        ]);
    }

    /**
     * One record, with its status ledger.
     */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->loadMissing(['manager', 'employmentType', 'user', 'statusHistory.actor']);

        return response()->json([
            'employee' => $this->present($employee, $request),
            'status_history' => $this->presenter->presentHistory($employee),
        ]);
    }

    /**
     * Create a record, optionally provisioning the login in the same request.
     */
    public function store(EmployeeStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $this->employees->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Employee created.',
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $request),
        ], Response::HTTP_CREATED);
    }

    /**
     * Edit a profile.
     */
    public function update(EmployeeUpdateRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $employee = $this->employees->update($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Employee updated.',
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $request),
        ]);
    }

    /**
     * Soft-delete a record.
     *
     * `force` exists for the departed-employee case the policy guards; the
     * delete is still a soft delete, so a forced one keeps the row and its
     * history rather than erasing a person from every report they appear in.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('delete', [$employee, $request->boolean('force')]);

        $this->employees->delete($employee, $request->user());

        return response()->json(['message' => 'Employee deleted.']);
    }

    /**
     * Move to another employment state.
     */
    public function changeStatus(
        EmployeeStatusChangeRequest $request,
        Employee $employee,
    ): JsonResponse {
        $this->authorize('changeStatus', $employee);

        $employee = $this->employees->changeStatus(
            $employee,
            EmployeeStatus::from($request->validated()['to']),
            $request->safe()->except('to'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Employment status updated.',
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $request),
        ]);
    }

    /**
     * Re-parent in the reporting line.
     */
    public function assignManager(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('assignManager', $employee);

        $validated = $request->validate([
            'manager_id' => ['present', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $employee = $this->employees->assignManager(
            $employee,
            $validated['manager_id'] === null ? null : Employee::findOrFail($validated['manager_id']),
            $request->user(),
        );

        return response()->json([
            'message' => 'Reporting line updated.',
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $request),
        ]);
    }

    /**
     * End an employment.
     */
    public function terminate(
        EmployeeTerminateRequest $request,
        Employee $employee,
    ): JsonResponse {
        $this->authorize('terminate', $employee);

        $employee = $this->employees->terminate(
            $employee,
            $request->validated()['reason'],
            $request->validated()['note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Employment ended.',
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $request),
        ]);
    }

    /**
     * Stream a photo from a signed link.
     *
     * Intentionally outside the auth/tenant groups, like every other HRMS file
     * download: no `SwitchTenant` has run, so the central tenant id rides
     * *inside the signature* as a `tenant` query param and the lookup happens
     * inside `TenantDatabaseManager::using()`. The employee id alone is
     * tenant-local and resolves to nothing on the central connection.
     *
     * @param  int  $employee  Tenant-local id, deliberately unbound.
     */
    public function photo(Request $request, int $employee): StreamedResponse
    {
        $tenant = Tenant::find((int) $request->query('tenant'));

        abort_if($tenant === null, 404);

        return app(TenantDatabaseManager::class)->using($tenant, function () use ($employee) {
            $record = Employee::query()->where('id', $employee)->first();

            abort_if($record === null || $record->photo_path === null, 404);

            if (! Storage::disk('public')->exists($record->photo_path)) {
                abort(404, 'File no longer exists.');
            }

            return Storage::disk('public')->download($record->photo_path);
        });
    }

    /**
     * Present one record, deciding sensitive access through the policy.
     *
     * @return array<string, mixed>
     */
    private function present(Employee $employee, Request $request): array
    {
        $record = $this->presenter->present($employee, $request->user()->can('viewSensitive', $employee));

        // The presenter has no business knowing the central tenant id, so the
        // signed URL is built here where the request is.
        $record['photo_url'] = $this->photoUrl($employee);

        return $record;
    }

    /**
     * A signed, tenant-scoped photo URL, or null.
     *
     * Not `Storage::url()`: that would serve the photo from the public disk
     * without a signature, so revoking access to it would mean unpublishing a
     * file rather than changing a permission.
     */
    private function photoUrl(Employee $employee): ?string
    {
        if ($employee->photo_path === null || $employee->photo_path === '') {
            return null;
        }

        return url()->temporarySignedRoute(
            'hrms.employees.photo',
            now()->addHours(1),
            // The central tenant id rides inside the signature. The employee id
            // is tenant-local, so on the central connection — which is what the
            // signed route runs on — it resolves to nothing at all.
            ['employee' => $employee->id, 'tenant' => app(TenantContext::class)->currentId()],
        );
    }

    /**
     * The uniform pagination payload — never `$page->toArray()`, which carries
     * the query string and the link headers along with it.
     *
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
