<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PlatformAudit;
use App\Services\Tenancy\TenantActivation;
use App\Services\Tenancy\TenantIntake;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin create/edit wizard for a tenant. A tenant is a `draft` row until
 * every required field is in; `submit` is the only call that provisions a
 * database. Self-registration runs the same TenantIntake rules.
 */
class TenantIntakeController extends Controller
{
    public function __construct(
        private readonly TenantIntake $intake,
        private readonly TenantActivation $activation,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    /** Create the draft. Only name + slug are needed to start; everything else can follow. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->partialRules(null, required: ['name', 'slug']));

        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['slug']),
            'status' => Tenant::STATUS_DRAFT,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);
        $this->intake->save($tenant, $data);

        app(PlatformAudit::class)->record($request, 'tenant.draft_created', Tenant::class, $tenant->id, [
            'slug' => $tenant->slug,
        ]);

        return response()->json(['message' => 'Draft saved.', ...$this->payload($tenant)], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($this->payload($tenant));
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->requireDraft($tenant);

        $data = $request->validate($this->partialRules($tenant));
        $this->intake->save($tenant, $data);

        return response()->json(['message' => 'Draft saved.', ...$this->payload($tenant->refresh())]);
    }

    public function submit(Request $request, Tenant $tenant): JsonResponse
    {
        $this->requireDraft($tenant);

        $request->validate([
            'admin_password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $this->activation->activate($tenant, $request->input('admin_password'));

        app(PlatformAudit::class)->record($request, 'tenant.intake_submitted', Tenant::class, $tenant->id, [
            'slug' => $tenant->slug,
        ]);

        return response()->json([
            'message' => 'Tenant submitted for provisioning.',
            ...$this->payload($tenant->refresh()),
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Tenant $tenant): array
    {
        return [
            'tenant' => $tenant,
            'intake' => $this->intake->state($tenant),
        ];
    }

    private function requireDraft(Tenant $tenant): void
    {
        if ($tenant->status !== Tenant::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'form' => 'Only a draft can be edited through intake; this tenant is already '.$tenant->status.'.',
            ]);
        }
    }

    /**
     * Every step's rules with `required` relaxed to `sometimes`: a draft saves
     * piecemeal, completeness is enforced at submit.
     *
     * @param  array<int, string>  $required
     * @return array<string, array<int, mixed>>
     */
    private function partialRules(?Tenant $tenant, array $required = []): array
    {
        $rules = [];
        foreach (TenantIntake::STEPS as $step) {
            foreach ($this->intake->rules($step, $tenant) as $field => $fieldRules) {
                if (in_array($field, $required, true)) {
                    $rules[$field] = $fieldRules;

                    continue;
                }
                $kept = array_values(array_filter(
                    $fieldRules,
                    fn ($r) => ! (is_string($r) && $r === 'required') && ! ($r instanceof RequiredIf),
                ));
                $rules[$field] = ['sometimes', 'nullable', ...$kept];
            }
        }

        return $rules;
    }
}
