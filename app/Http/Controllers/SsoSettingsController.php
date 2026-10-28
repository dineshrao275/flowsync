<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoSettings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Tenant admins (`tenant.manage`) configure OIDC sign-in. The client secret is write-only. */
class SsoSettingsController extends Controller
{
    public function __construct(
        private readonly SsoSettings $settings,
        private readonly OidcClient $oidc,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = $this->tenant();

        return response()->json(['sso' => $this->settings->present($tenant), 'redirect_uri' => url("/api/sso/{$tenant->slug}/callback")]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'enforce' => ['required', 'boolean'],
            'jit' => ['required', 'boolean'],
            'issuer' => ['required_if:enabled,true', 'nullable', 'url', 'max:255'],
            'client_id' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:512'],
            'allowed_domains' => ['present', 'array', 'max:20'],
            'allowed_domains.*' => ['string', 'max:120', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'default_role' => ['required', 'string', 'exists:roles,slug'],
        ]);

        if ($data['default_role'] === 'admin') {
            throw ValidationException::withMessages(['default_role' => __('New accounts cannot be created as administrators.')]);
        }

        if ($data['enabled']) {
            if (! $data['client_secret'] && ! $this->settings->secret($tenant)) {
                throw ValidationException::withMessages(['client_secret' => __('Enter the client secret from your identity provider.')]);
            }
            // Prove the provider answers before we let the tenant depend on it (also runs the SSRF guard).
            try {
                $this->oidc->discover($data['issuer']);
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable) {
                throw ValidationException::withMessages(['issuer' => __('Could not read the identity provider\'s configuration from that address.')]);
            }
        }

        $this->settings->save($tenant, $data);

        return response()->json(['message' => __('Single sign-on settings saved.'), 'sso' => $this->settings->present($tenant->refresh())]);
    }

    private function tenant(): Tenant
    {
        $id = app(TenantContext::class)->currentId();
        abort_if($id === null, 404);

        return Tenant::query()->findOrFail($id);
    }
}
