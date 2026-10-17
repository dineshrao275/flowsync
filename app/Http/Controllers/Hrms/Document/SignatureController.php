<?php

namespace App\Http\Controllers\Hrms\Document;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Document\DocumentSignature;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Services\Hrms\Document\SignatureService;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E-signature requests (F6.7). HR (`hrms.documents.manage`) asks and cancels and sees every request; the signer sees
 * and answers only their own. Signing needs the signer's password again. `verify` is public on purpose — anyone holding
 * a receipt code can confirm it — and answers only with what a receipt proves.
 */
class SignatureController extends Controller
{
    public function __construct(private readonly SignatureService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DocumentSignature::query()->with(['document:id,title', 'employee:id,name,user_id'])->latest('id')->limit(200);

        if (! $user->hasPermission('hrms.documents.manage')) {
            $mine = Employee::query()->where('user_id', $user->id)->value('id');
            $query->where('employee_id', $mine ?? 0);
        }

        return response()->json(['signatures' => $query->get()->map(fn ($s) => $this->present($s, $user))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('hrms.documents.manage'), 403);
        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:employee_documents,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $sig = $this->service->request(EmployeeDocument::findOrFail($data['document_id']), Employee::findOrFail($data['employee_id']), $data['message'] ?? null, $request->user());

        return response()->json(['message' => __('Signature requested.'), 'signature' => $this->present($sig->load(['document', 'employee']), $request->user())], 201);
    }

    public function sign(Request $request, DocumentSignature $signature): JsonResponse
    {
        $data = $request->validate(['typed_name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $sig = $this->service->sign($signature, $request->user(), $data['typed_name'], $data['password'], $request->ip(), $request->userAgent());

        return response()->json(['message' => __('Signed.'), 'signature' => $this->present($sig->load(['document', 'employee']), $request->user())]);
    }

    public function decline(Request $request, DocumentSignature $signature): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['message' => __('Declined.'), 'signature' => $this->present($this->service->decline($signature, $request->user(), $data['reason'])->load(['document', 'employee']), $request->user())]);
    }

    public function cancel(Request $request, DocumentSignature $signature): JsonResponse
    {
        abort_unless($request->user()->hasPermission('hrms.documents.manage'), 403);

        return response()->json(['message' => __('Request cancelled.'), 'signature' => $this->present($this->service->cancel($signature, $request->user())->load(['document', 'employee']), $request->user())]);
    }

    /** Public: confirm a receipt code. The tenant rides inside the code (`fsig_<tenantId>_<random>`). */
    public function verify(string $code): JsonResponse
    {
        if (! preg_match('/^fsig_(\d+)_[A-Za-z0-9]{32}$/', $code, $m)) {
            return response()->json(['found' => false], 404);
        }

        $dbm = app(TenantDatabaseManager::class);
        $dbm->connectSystem();
        $tenant = Tenant::query()->find((int) $m[1]);

        $result = $tenant ? $dbm->using($tenant, function () use ($code) {
            $sig = DocumentSignature::query()->with(['document:id,title', 'employee:id,name,user_id'])->where('verification_code', $code)->where('status', 'signed')->first();
            if ($sig === null) {
                return null;
            }

            return ['found' => true] + $this->service->check($sig) + [
                'document' => $sig->document?->title, 'signer' => $sig->typed_name, 'signed_at' => $sig->signed_at->toIso8601String(), 'document_sha256' => $sig->document_sha256,
            ];
        }) : null;

        return $result ? response()->json($result) : response()->json(['found' => false], 404);
    }

    /** @return array<string, mixed> */
    private function present(DocumentSignature $s, $user): array
    {
        return [
            'id' => $s->id,
            'document' => ['id' => $s->document_id, 'title' => $s->document?->title],
            'signer' => ['id' => $s->employee_id, 'name' => $s->employee?->name],
            'status' => $s->status,
            'message' => $s->message,
            'signed_at' => $s->signed_at?->toIso8601String(),
            'verification_code' => $s->status === 'signed' ? $s->verification_code : null,
            'decline_reason' => $s->decline_reason,
            'can_sign' => $s->isPending() && $s->employee?->user_id === $user->id,
            'can_cancel' => $s->isPending() && $user->hasPermission('hrms.documents.manage'),
        ];
    }
}
