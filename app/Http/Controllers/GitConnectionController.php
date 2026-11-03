<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GitSetupRequest;
use App\Models\Tenant;
use App\Services\Integrations\Git\GitConnectionException;
use App\Services\Integrations\Git\GitConnectionPresenter;
use App\Services\Integrations\Git\GitConnectionService;
use App\Services\Integrations\Git\GitProviderException;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Tenant admins connect GitHub accounts (App installations) and disconnect them. */
class GitConnectionController extends Controller
{
    public function __construct(private readonly GitConnectionService $connections) {}

    public function index(): JsonResponse
    {
        $connections = $this->connections->listForTenant($this->tenant());
        $health = $this->connections->healthFor($connections);

        return response()->json([
            'enabled' => (bool) config('git.enabled'),
            'connections' => $connections->map(fn ($c) => GitConnectionPresenter::present($c, $health[$c->id] ?? []))->values()->all(),
        ]);
    }

    /** Where to send the browser to install the app. JSON, not a redirect, so the SPA can navigate. */
    public function install(Request $request): JsonResponse
    {
        try {
            return response()->json(['url' => $this->connections->installUrl($this->tenant(), $request->user())]);
        } catch (GitConnectionException|GitProviderException $e) {
            return $this->failure($e);
        }
    }

    public function sync(string $uuid): JsonResponse
    {
        try {
            $this->connections->sync($this->tenant(), $uuid);
        } catch (GitConnectionException $e) {
            return $this->failure($e);
        }

        return response()->json(['message' => __('Repository sync started.')], 202);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        try {
            $this->connections->disconnect($this->tenant(), $uuid, $request->user(), $request);
        } catch (GitConnectionException $e) {
            return $this->failure($e);
        }

        return response()->json(['message' => __('Git account disconnected.')]);
    }

    /**
     * GitHub's post-installation redirect. Public by nature (the state it carries is the proof),
     * so it never answers with data: it sends the browser back to the SPA with a result flag.
     */
    public function setup(GitSetupRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->connections->handleSetup($data['state'], (int) $data['installation_id'], $data['code'] ?? null, $request);
        } catch (GitConnectionException|GitProviderException $e) {
            return redirect('/app/integrations?'.http_build_query(['git_error' => $e->getMessage()]));
        }

        return redirect('/app/integrations?git_connected=1');
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail(app(TenantContext::class)->currentId());
    }

    private function failure(GitConnectionException|GitProviderException $e): JsonResponse
    {
        $status = $e instanceof GitConnectionException ? $e->status : 502;

        return response()->json(['message' => $e->getMessage(), 'errors' => ['form' => [$e->getMessage()]]], $status);
    }
}
