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
use App\Policies\Hrms\Employee\EmployeePolicy;
use App\Services\Hrms\Employee\EmployeeAccessLogger;
use App\Services\Hrms\Employee\EmployeeDirectoryQuery;
use App\Services\Hrms\Employee\EmployeePhotoService;
use App\Services\Hrms\Employee\EmployeePresenter;
use App\Services\Hrms\Employee\EmployeeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        private readonly EmployeePhotoService $photos,
        private readonly EmployeeAccessLogger $accessLog,
        private readonly EmployeeDirectoryQuery $directory,
    ) {}

    /**
     * The directory.
     */
    public function index(EmployeeIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->filters();
        $page = $this->employees->list($filters);

        // Masked even for a caller who could read the full record one at a time.
        // A list of fifty people with their dates of birth and home addresses is
        // a data-mining surface that no single-record screen is: nothing in the
        // directory's job needs a home address, and paging through it is how a
        // bulk harvest looks. The profile screen is where a privileged reader
        // gets the real values.
        $rows = $page->getCollection()
            ->map(fn (Employee $employee) => $this->presenter->present($employee))
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

        // Resolved once and reused, so the payload and the access log can never
        // disagree about whether the caller saw the personal fields.
        $sensitive = $this->maySeeSensitive($request, $employee);

        $this->accessLog->recordView($employee, $request->user(), $request->ip(), $sensitive);

        return response()->json([
            'employee' => $this->present($employee, $sensitive, $request),
            'status_history' => $this->presenter->presentHistory($employee),
            // The profile's Audit tab fetches the record's trail by its
            // stored morph class — shipping it here beats hardcoding an
            // FQCN in JS that rots the day a morph map lands.
            'subject_type' => $employee->getMorphClass(),
            // The manager picker needs its options, and a second request for them
            // would leave the screen with an empty select for one round trip.
            'filters' => ['managers' => $this->directory->managerOptions()],
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
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $this->maySeeSensitive($request, $employee), $request),
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
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $this->maySeeSensitive($request, $employee), $request),
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
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $this->maySeeSensitive($request, $employee), $request),
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
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $this->maySeeSensitive($request, $employee), $request),
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
            'employee' => $this->present($employee->load(['manager', 'employmentType', 'user']), $this->maySeeSensitive($request, $employee), $request),
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
    /**
     * The photo, served from its signed link.
     *
     * Intentionally outside the auth/tenant groups, like the task attachment
     * download: the signature is the credential, so a fresh browser tab or an
     * <img> tag works. Every value it needs therefore arrives inside the signed
     * query string.
     */
    public function photo(Request $request, int $employee): StreamedResponse
    {
        $actor = $request->query('actor');

        return $this->photos->stream(
            $employee,
            (int) $request->query('tenant'),
            $actor === null ? null : (int) $actor,
            $request->ip(),
        );
    }

    private function maySeeSensitive(Request $request, ?Employee $employee = null): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        // A class name is a valid second argument: Laravel drops it before
        // calling the policy, so the policy's `$employee` parameter stays
        // optional and the class-level case needs no branch here.
        return $user->can('viewSensitive', $employee ?? Employee::class);
    }

    private function present(Employee $employee, bool $sensitive, Request $request): array
    {
        $record = $this->presenter->present($employee, $sensitive);

        // A photo is a face, so it is gated exactly like the other personal
        // fields, and the initials fallback in the avatar slot takes the place.
        $record['photo_url'] = $sensitive
            ? $this->photos->url($employee, $request->user()?->id)
            : null;

        return $record;
    }

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
