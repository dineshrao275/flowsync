<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeLoan;
use App\Models\User;
use App\Services\Hrms\Payroll\LoanService;
use App\Services\Hrms\Shared\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loans and advances. A person asks for their own; payroll runners (`hrms.payroll.run`) see and raise any.
 * Deciding is the approval engine's call.
 */
class EmployeeLoanController extends Controller
{
    public function __construct(
        private readonly LoanService $service,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = Employee::query()->where('user_id', $user->id)->value('id');
        $rows = EmployeeLoan::query()->with(['employee:id,name,employee_code', 'approval', 'schedule'])->latest('id')->limit(100)->get();

        if (! $user->hasPermission('hrms.payroll.run')) {
            $rows = $rows->filter(fn (EmployeeLoan $l) => ($mine !== null && $l->employee_id === $mine)
                || ($l->approval !== null && $this->approvals->canAct($l->approval, $user)))->values();
        }

        return response()->json(['loans' => $rows->map(fn ($l) => $this->present($l, $user))->values(), 'kinds' => EmployeeLoan::KINDS]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'kind' => ['required', 'string'],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'installments' => ['required', 'integer', 'min:1', 'max:60'],
            'first_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'first_month' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $own = Employee::query()->where('user_id', $user->id)->first();
        $employee = ! empty($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : $own;
        abort_if($employee === null, 422, __('You need an employment record to ask for a loan.'));
        abort_unless($employee->id === $own?->id || $user->hasPermission('hrms.payroll.run'), 403, __('You cannot request a loan for this person.'));

        $loan = $this->service->request($employee, $data['kind'], (string) $data['principal'], (int) $data['installments'], (int) $data['first_year'], (int) $data['first_month'], $data['reason'] ?? null, $user);

        return response()->json(['message' => __('Loan requested.'), 'loan' => $this->present($this->load($loan), $user)], 201);
    }

    public function approve(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['message' => __('Loan decided.'), 'loan' => $this->present($this->load($this->service->approve($loan, $request->user(), $data['note'] ?? null)), $request->user())]);
    }

    public function reject(Request $request, EmployeeLoan $loan): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json(['message' => __('Loan rejected.'), 'loan' => $this->present($this->load($this->service->reject($loan, $request->user(), $data['note'])), $request->user())]);
    }

    public function withdraw(Request $request, EmployeeLoan $loan): JsonResponse
    {
        abort_unless($loan->requested_by_user_id === $request->user()->id || $request->user()->hasPermission('hrms.payroll.run'), 403);

        return response()->json(['message' => __('Loan withdrawn.'), 'loan' => $this->present($this->load($this->service->withdraw($loan, $request->user())), $request->user())]);
    }

    public function stop(Request $request, EmployeeLoan $loan): JsonResponse
    {
        abort_unless($request->user()->hasPermission('hrms.payroll.run'), 403);

        return response()->json(['message' => __('Loan stopped.'), 'loan' => $this->present($this->load($this->service->cancelActive($loan, $request->user())), $request->user())]);
    }

    private function load(EmployeeLoan $loan): EmployeeLoan
    {
        return $loan->load(['employee:id,name,employee_code', 'approval', 'schedule']);
    }

    /** @return array<string, mixed> */
    private function present(EmployeeLoan $l, User $user): array
    {
        return [
            'id' => $l->id,
            'employee' => $l->employee ? ['id' => $l->employee->id, 'name' => $l->employee->name] : null,
            'kind' => $l->kind,
            'principal' => (string) $l->principal,
            'installments' => $l->installments,
            'installment_amount' => (string) $l->installment_amount,
            'balance' => (string) $l->balance,
            'first_year' => $l->first_year,
            'first_month' => $l->first_month,
            'reason' => $l->reason,
            'status' => $l->status,
            'decision_note' => $l->decision_note,
            'schedule' => $l->schedule->map(fn ($i) => ['number' => $i->number, 'due_year' => $i->due_year, 'due_month' => $i->due_month, 'amount' => (string) $i->amount, 'status' => $i->status])->values(),
            'can_decide' => $l->isPending() && $l->approval !== null && $this->approvals->canAct($l->approval, $user),
            'can_stop' => $l->status === 'active' && $user->hasPermission('hrms.payroll.run'),
        ];
    }
}
