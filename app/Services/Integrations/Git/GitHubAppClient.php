<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GitHub App REST client. Authenticates as the app with a JWT to mint installation tokens, then
 * as the installation for everything else. Response bodies are never surfaced to callers: an
 * error becomes a GitProviderException carrying only a safe message, the status and Retry-After.
 */
class GitHubAppClient implements GitProvider
{
    /** Pages fetched per sync, a ceiling against a runaway pagination loop. */
    public const MAX_PAGES = 50;

    public function name(): string
    {
        return 'github';
    }

    public function installUrl(string $state): string
    {
        $slug = (string) config('git.providers.github.slug');
        if ($slug === '') {
            throw new GitProviderException(__('The GitHub App is not configured on this server.'));
        }

        return $this->webBase()."/apps/{$slug}/installations/new?".http_build_query(['state' => $state]);
    }

    public function installation(int $installationId): array
    {
        $body = $this->decode($this->appRequest()->get("/app/installations/{$installationId}"));

        return [
            'id' => (int) $body['id'],
            'account' => [
                'login' => (string) ($body['account']['login'] ?? ''),
                'type' => (string) ($body['account']['type'] ?? 'Organization'),
            ],
            'suspended_at' => $body['suspended_at'] ?? null,
            'permissions' => (array) ($body['permissions'] ?? []),
        ];
    }

    public function userCanAccessInstallation(string $code, int $installationId): bool
    {
        $config = (array) config('git.providers.github');
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return false;
        }

        $exchange = $this->decode(Http::acceptJson()->timeout((int) config('git.http_timeout'))
            ->post($this->webBase().'/login/oauth/access_token', [
                'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'], 'code' => $code,
            ]));
        $userToken = (string) ($exchange['access_token'] ?? '');
        if ($userToken === '') {
            return false;
        }

        // The user token only proves who is asking; it is dropped as soon as the list is read.
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->decode($this->base()->withToken($userToken)->get('/user/installations', [
                'per_page' => (int) config('git.per_page'), 'page' => $page,
            ]));
            foreach ((array) ($body['installations'] ?? []) as $installation) {
                if ((int) ($installation['id'] ?? 0) === $installationId) {
                    return true;
                }
            }
            if (count((array) ($body['installations'] ?? [])) < (int) config('git.per_page')) {
                return false;
            }
        }

        return false;
    }

    public function installationRepositories(int $installationId, int $page = 1): array
    {
        $perPage = (int) config('git.per_page');
        $body = $this->decode($this->installationRequest($installationId)->get('/installation/repositories', [
            'per_page' => $perPage, 'page' => $page,
        ]));
        $repositories = array_values((array) ($body['repositories'] ?? []));

        return ['repositories' => $repositories, 'next_page' => count($repositories) >= $perPage ? $page + 1 : null];
    }

    public function installationToken(int $installationId): string
    {
        $key = "git:token:{$installationId}";
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($key);
            }
        }

        $body = $this->decode($this->appRequest()->post("/app/installations/{$installationId}/access_tokens"));
        $token = (string) ($body['token'] ?? '');
        if ($token === '') {
            throw new GitProviderException(__('GitHub did not return an access token.'));
        }

        $expiresIn = max(60, (int) strtotime((string) ($body['expires_at'] ?? '')) - time() - 120);
        Cache::put($key, Crypt::encryptString($token), min($expiresIn, (int) config('git.token_cache_ttl')));

        return $token;
    }

    private function base(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('git.providers.github.api_url'), '/'))
            ->timeout((int) config('git.http_timeout'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);
    }

    private function appRequest(): PendingRequest
    {
        return $this->base()->withToken(GitHubAppJwt::fromConfig()->token());
    }

    private function installationRequest(int $installationId): PendingRequest
    {
        return $this->base()->withToken($this->installationToken($installationId));
    }

    /** github.com for the public API, the host root for Enterprise (`https://host/api/v3` -> `https://host`). */
    private function webBase(): string
    {
        $api = rtrim((string) config('git.providers.github.api_url'), '/');

        return $api === 'https://api.github.com' ? 'https://github.com' : (string) preg_replace('#/api/v3$#', '', $api);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->failed()) {
            $retry = $response->header('Retry-After');
            Log::warning('git.provider_error', ['status' => $response->status()]);

            throw new GitProviderException(
                __('GitHub refused the request (:status).', ['status' => $response->status()]),
                $response->status(),
                $retry !== '' ? (int) $retry : null,
            );
        }

        return (array) $response->json();
    }
}
