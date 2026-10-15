<?php

namespace App\Http\Controllers;

use App\Models\ImpersonationLog;
use App\Models\PlatformSetting;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Services\PlatformAudit;
use App\Services\Security\SupportAccessGrants;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consent-based support access (P8.4). Tenant side: an admin (`support.manage`)
 * grants, lists and revokes windows; platform side: the platform lists the
 * grants it may use and toggles whether consent is mandatory.
 */
class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccessGrants $grants) {}

    public function index(): JsonResponse
    {
        $tenant = $this->tenant();

        return response()->json([
            'grants' => SupportAccessGrant::where('tenant_id', $tenant->id)->latest('id')->limit(50)->get()->map(fn ($g) => $this->present($g)),
            'consent_required' => $this->grants->consentRequired(),
            'limits' => [
                'max_hours' => (int) config('tenancy.support_access.max_hours'),
                'max_session_minutes' => (int) config('tenancy.support_access.max_session_minutes'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'in:read_only,write'],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tenancy.support_access.max_hours', 72)],
            'session_minutes' => ['sometimes', 'integer', 'min:5', 'max:'.(int) config('tenancy.support_access.max_session_minutes', 120)],
            'note' => ['required', 'string', 'min:8', 'max:500'],
            'support_ticket_id' => ['nullable', 'integer'],
        ]);

        $tenant = $this->tenant();
        $grant = $this->grants->grant($tenant, $request->user(), $data);

        app(PlatformAudit::class)->record($request, 'support_access.granted', 'support_access_grants', $grant->id, [
            'tenant_id' => $tenant->id, 'mode' => $grant->mode, 'expires_at' => $grant->expires_at->toIso8601String(),
            'granted_by' => $request->user()->email,
        ]);

        return response()->json(['message' => 'Support access granted.', 'grant' => $this->present($grant)], 201);
    }

    public function destroy(Request $request, int $grant): JsonResponse
    {
        $tenant = $this->tenant();
        // Another tenant's grant is a 404, never a 403: its existence is not ours to confirm.
        $model = SupportAccessGrant::where('tenant_id', $tenant->id)->findOrFail($grant);

        $this->grants->revoke($model, $request->user());

        app(PlatformAudit::class)->record($request, 'support_access.revoked', 'support_access_grants', $model->id, [
            'tenant_id' => $tenant->id, 'revoked_by' => $request->user()->email,
        ]);

        return response()->json(['message' => 'Support access revoked.', 'grant' => $this->present($model->refresh())]);
    }

    /** Platform side: grants currently usable, optionally for one tenant. */
    public function platformIndex(Request $request): JsonResponse
    {
        $query = SupportAccessGrant::active()->with('tenant:id,name,slug')->latest('id');
        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->query('tenant_id'));
        }

        return response()->json([
            'grants' => $query->limit(100)->get()->map(fn ($g) => $this->present($g) + ['tenant' => $g->tenant?->only(['id', 'name', 'slug'])]),
            'consent_required' => $this->grants->consentRequired(),
        ]);
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $data = $request->validate(['consent_required' => ['required', 'boolean']]);
        $before = $this->grants->consentRequired();

        PlatformSetting::set(SupportAccessGrants::POLICY_KEY, (bool) $data['consent_required']);

        app(PlatformAudit::class)->diff($request, 'platform.support_consent_policy_updated', 'platform_settings', null,
            ['consent_required' => $before], ['consent_required' => (bool) $data['consent_required']]);

        return response()->json(['message' => 'Policy saved.', 'consent_required' => (bool) $data['consent_required']]);
    }

    /** @return array<string, mixed> */
    private function present(SupportAccessGrant $g): array
    {
        return [
            'id' => $g->id,
            'mode' => $g->mode,
            'note' => $g->note,
            'support_ticket_id' => $g->support_ticket_id,
            'granted_by' => ['name' => $g->granted_by_name, 'email' => $g->granted_by_email],
            'expires_at' => $g->expires_at?->toIso8601String(),
            'session_minutes' => $g->session_minutes,
            'revoked_at' => $g->revoked_at?->toIso8601String(),
            'active' => $g->isActive(),
            'uses' => $g->uses,
            'last_used_at' => $g->last_used_at?->toIso8601String(),
            'sessions' => ImpersonationLog::where('support_access_grant_id', $g->id)->latest('id')->limit(10)
                ->get(['started_at', 'ended_at', 'mode', 'reason'])->toArray(),
        ];
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail(app(TenantContext::class)->currentId());
    }
}
