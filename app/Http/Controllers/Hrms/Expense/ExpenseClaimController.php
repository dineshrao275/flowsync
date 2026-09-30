<?php

namespace App\Http\Controllers\Hrms\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\ExpenseClaimItemsRequest;
use App\Http\Requests\Hrms\ExpenseClaimRequest;
use App\Http\Requests\Hrms\ExpenseDecideRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\User;
use App\Services\Hrms\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Expense/HRMS — claims from filing to decision over HTTP.
 *
 * Thin: it authorizes (listing and reading are self-or-view, filing and
 * submitting self-or-manage, deciding the approve permission), hands the
 * payload to the service, and shapes the envelope. The chain, the locks
 * and the money rules live in the service — including the verdict split,
 * which is one endpoint with a `verdict` rather than two verbs that would
 * drift apart.
 */
class ExpenseClaimController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExpenseClaim::class);

        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'period_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'period_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $query = ExpenseClaim::query()
            ->with(['employee:id,employee_code,name', 'items'])
            ->orderByDesc('id');

        if (! $request->user()->hasPermission('hrms.expenses.view')
            && ! $request->user()->hasPermission('hrms.expenses.manage')) {
            $query->where('employee_id', $this->employeeId($request->user()));
        }

        foreach (array_filter($filters, fn ($value) => $value !== null) as $field => $value) {
            $query->where($field, $value);
        }

        return response()->json([
            'claims' => $query->get()->map(fn (ExpenseClaim $claim): array => $this->present($claim))->all(),
        ]);
    }

    public function show(ExpenseClaim $claim): JsonResponse
    {
        $this->authorize('view', $claim);

        return response()->json(['claim' => $this->present($claim->load(['employee', 'items.category']))]);
    }

    public function store(ExpenseClaimRequest $request): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $this->authorize('file', [ExpenseClaim::class, $employee]);

        $items = $data['items'] ?? [];
        unset($data['items'], $data['employee_id']);

        $claim = $this->expenses->create($employee, $data, $items, $request->user());

        return response()->json([
            'message' => 'Claim filed.',
            'claim' => $this->present($claim),
        ], Response::HTTP_CREATED);
    }

    public function setItems(ExpenseClaimItemsRequest $request, ExpenseClaim $claim): JsonResponse
    {
        $this->authorize('submit', $claim);

        $updated = $this->expenses->setItems($claim, $request->validated()['items'], $request->user());

        return response()->json([
            'message' => 'Claim lines updated.',
            'claim' => $this->present($updated),
        ]);
    }

    public function submit(Request $request, ExpenseClaim $claim): JsonResponse
    {
        $this->authorize('submit', $claim);

        $submitted = $this->expenses->submit($claim, $request->user());

        return response()->json([
            'message' => 'Claim submitted.',
            'claim' => $this->present($submitted),
        ]);
    }

    public function decide(ExpenseDecideRequest $request, ExpenseClaim $claim): JsonResponse
    {
        $this->authorize('decide', $claim);

        $data = $request->validated();

        $decided = $data['verdict'] === 'approve'
            ? $this->expenses->approve($claim, $request->user(), $data['approved_amount'] ?? null, $data['reason'] ?? null)
            : $this->expenses->reject($claim, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'message' => $data['verdict'] === 'approve' ? 'Claim approved.' : 'Claim rejected.',
            'claim' => $this->present($decided),
        ]);
    }

    /**
     * The caller's employment record id, or zero when there is none — a
     * login without a record lists nothing, never everyone.
     */
    private function employeeId(User $user): int
    {
        return (int) (Employee::where('user_id', $user->id)->value('id') ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ExpenseClaim $claim): array
    {
        $claim->loadMissing(['employee:id,employee_code,name', 'items.category:id,name,slug']);

        return [
            'id' => $claim->id,
            'employee_id' => $claim->employee_id,
            'employee' => $claim->employee ? [
                'id' => $claim->employee->id,
                'employee_code' => $claim->employee->employee_code,
                'name' => $claim->employee->displayName(),
            ] : null,
            'claim_number' => $claim->claim_number,
            'claim_date' => $claim->claim_date->toDateString(),
            'period_year' => $claim->period_year,
            'period_month' => $claim->period_month,
            'purpose' => $claim->purpose,
            'description' => $claim->description,
            'currency' => $claim->currency,
            'total_amount' => (string) $claim->total_amount,
            'approved_amount' => $claim->approved_amount === null ? null : (string) $claim->approved_amount,
            'reimbursed_amount' => $claim->reimbursed_amount === null ? null : (string) $claim->reimbursed_amount,
            'status' => $claim->status->value,
            'approval_id' => $claim->approval_id,
            'paid_in_payroll_run_id' => $claim->paid_in_payroll_run_id,
            'paid_via' => $claim->paid_via,
            'items' => $claim->items->map(fn ($item): array => [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'category' => $item->category ? ['id' => $item->category->id, 'name' => $item->category->name, 'slug' => $item->category->slug] : null,
                'description' => $item->description,
                'amount' => (string) $item->amount,
                'spent_at' => $item->spent_at?->toDateString(),
                'vendor' => $item->vendor,
                'receipt_document_id' => $item->receipt_document_id,
                'is_billable' => $item->is_billable,
                'notes' => $item->notes,
            ])->all(),
        ];
    }
}
