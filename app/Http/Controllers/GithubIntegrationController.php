<?php

namespace App\Http\Controllers;

use App\Http\Requests\GithubIntegrationUpdateRequest;
use App\Models\Tenant;
use App\Services\Integrations\GithubIntegration;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public webhook receiver (signed) and the tenant admin's switch for it. */
class GithubIntegrationController extends Controller
{
    public function __construct(
        private readonly GithubIntegration $github,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    public function receive(Request $request, int $tenantId): JsonResponse
    {
        $this->dbm->connectSystem();
        $tenant = Tenant::query()->find($tenantId);
        $secret = $tenant && $tenant->isServiceable() && $this->github->isEnabled($tenant) ? $this->github->secret($tenant) : null;

        // Unknown tenant, switched off, or bad signature all look the same.
        if ($secret === null || ! $this->github->validSignature($request->getContent(), $request->header('X-Hub-Signature-256'), $secret)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $event = (string) $request->header('X-GitHub-Event');
        if ($event === 'ping') {
            return response()->json(['message' => 'pong']);
        }

        $linked = $this->dbm->using($tenant, fn () => $this->github->handle($event, (array) $request->json()->all()));

        return response()->json(['linked' => $linked], 202);
    }

    public function show(): JsonResponse
    {
        $tenant = $this->tenant();

        return response()->json(['enabled' => (bool) ($tenant->settings['github']['enabled'] ?? false), 'has_secret' => ! empty($tenant->settings['github']['secret']), 'payload_url' => url("/api/integrations/github/{$tenant->id}")]);
    }

    public function update(GithubIntegrationUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenant = $this->tenant();
        $secret = $this->github->configure($tenant, $data['enabled'], (bool) ($data['rotate'] ?? false));

        return response()->json([
            'message' => __('GitHub integration saved.'),
            'enabled' => $data['enabled'], 'has_secret' => ! empty($tenant->refresh()->settings['github']['secret']),
            'payload_url' => url("/api/integrations/github/{$tenant->id}"),
            'secret' => $secret, // shown once
        ]);
    }

    private function tenant(): Tenant
    {
        $id = app(TenantContext::class)->currentId();
        abort_if($id === null, 404);

        return Tenant::query()->findOrFail($id);
    }
}
