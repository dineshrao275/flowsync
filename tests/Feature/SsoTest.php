<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F10.2 — OIDC sign-in against a faked provider: PKCE start, RS256 verification, JIT provisioning, enforcement. */
class SsoTest extends TestCase
{
    use IsolatesDatabase;

    private const ISSUER = 'https://idp.example.test';

    private $key;

    private array $jwk;

    private ?string $idToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['webhooks.resolver' => fn () => ['93.184.216.34']]);
        Cache::flush();

        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($this->key)['rsa'];
        $this->jwk = ['kty' => 'RSA', 'kid' => 'k1', 'n' => OidcClient::b64($d['n']), 'e' => OidcClient::b64($d['e'])];

        $tenant = $this->tenant();
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/auth', 'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => Http::response(['keys' => [$this->jwk]]),
            self::ISSUER.'/token' => fn () => Http::response(['id_token' => $this->idToken]),
        ]);

        app(SsoSettings::class)->save($tenant, [
            'enabled' => true, 'enforce' => false, 'jit' => false, 'issuer' => self::ISSUER, 'client_id' => 'flowsync', 'client_secret' => 's3cret',
            'allowed_domains' => [], 'default_role' => 'viewer',
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->where('slug', 'acme')->firstOrFail();
    }

    private function jwt(array $claims, string $alg = 'RS256', ?string $signWith = null): string
    {
        $h = OidcClient::b64(json_encode(['alg' => $alg, 'kid' => 'k1', 'typ' => 'JWT']));
        $p = OidcClient::b64(json_encode($claims));
        $sig = '';
        if ($alg === 'RS256') {
            openssl_sign("$h.$p", $sig, $signWith ? openssl_pkey_new(['private_key_bits' => 2048]) : $this->key, OPENSSL_ALGO_SHA256);
        }

        return "$h.$p.".OidcClient::b64($sig);
    }

    /** Run start → callback with an ID token built from `$claims` (nonce filled in); returns the callback response. */
    private function signIn(array $claims, string $alg = 'RS256', bool $badKey = false)
    {
        $start = $this->get('/api/sso/acme/start')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);

        $this->idToken = $this->jwt($claims + ['iss' => self::ISSUER, 'aud' => 'flowsync', 'exp' => time() + 300, 'nonce' => $q['nonce'], 'email_verified' => true], $alg, $badKey ? 'other' : null);

        return $this->get('/api/sso/acme/callback?code=abc&state='.$q['state']);
    }

    public function test_a_valid_token_signs_an_existing_user_in(): void
    {
        $this->signIn(['email' => 'editor@flowsync.test', 'name' => 'Ed'])->assertRedirect('/app/');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'editor@flowsync.test');
    }

    public function test_bad_signature_wrong_algorithm_and_unknown_state_are_refused(): void
    {
        $this->signIn(['email' => 'editor@flowsync.test'], 'RS256', true)->assertRedirectContains('sso_error=sso_failed');
        $this->signIn(['email' => 'editor@flowsync.test'], 'none')->assertRedirectContains('sso_error=sso_failed');
        $this->get('/api/sso/acme/callback?code=x&state=nope')->assertRedirectContains('sso_error=sso_failed');
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_an_unknown_email_needs_jit_and_never_becomes_an_admin(): void
    {
        $this->signIn(['email' => 'newbie@flowsync.test'])->assertRedirectContains('sso_error=sso_no_account');

        app(SsoSettings::class)->save($this->tenant(), ['jit' => true, 'default_role' => 'viewer', 'allowed_domains' => ['flowsync.test']]);
        $this->signIn(['email' => 'newbie@flowsync.test', 'name' => 'New Bie'])->assertRedirect('/app/');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'newbie@flowsync.test');
        $this->assertTrue(TenantUserRouting::where('email', 'newbie@flowsync.test')->exists());

        app(SsoSettings::class)->save($this->tenant(), ['default_role' => 'admin']);
        $this->postJson('/api/auth/logout');
        $this->signIn(['email' => 'second@flowsync.test'])->assertRedirectContains('sso_error=sso_no_account');

        app(SsoSettings::class)->save($this->tenant(), ['default_role' => 'viewer', 'allowed_domains' => ['other.test']]);
        $this->signIn(['email' => 'third@flowsync.test'])->assertRedirectContains('sso_error=sso_email');
    }

    public function test_enforcement_stops_password_login_except_for_the_default_user(): void
    {
        app(SsoSettings::class)->save($this->tenant(), ['enforce' => true]);

        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertStatus(422);

        $default = $this->dbManagerDefaultUser();
        $this->postJson('/api/auth/login', ['email' => $default, 'password' => 'password'])->assertOk();
    }

    private function dbManagerDefaultUser(): string
    {
        $this->connectTenant('acme');

        return User::query()->where('is_default', true)->value('email');
    }

    public function test_settings_never_expose_the_secret_and_need_tenant_manage(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'viewer@flowsync.test', 'password' => 'password'])->assertOk();
        $this->getJson('/api/sso-settings')->assertForbidden();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $res = $this->getJson('/api/sso-settings')->assertOk();
        $this->assertTrue($res->json('sso.has_secret'));
        $this->assertStringNotContainsString('s3cret', $res->getContent());
        $this->assertArrayNotHasKey('client_secret', $res->json('sso'));
    }

    public function test_saving_settings_without_a_secret_keeps_the_stored_one(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->assertTrue($this->getJson('/api/sso-settings')->json('sso.has_secret'));

        // Found by the form audit: an omitted client_secret used to be a 500; it means "keep what is stored".
        $this->putJson('/api/sso-settings', [
            'enabled' => false, 'enforce' => false, 'jit' => false,
            'allowed_domains' => [], 'default_role' => 'viewer',
        ])->assertOk();

        $this->assertTrue($this->getJson('/api/sso-settings')->json('sso.has_secret'));
    }
}
