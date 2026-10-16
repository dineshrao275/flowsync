<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Services\UsageMeter;
use Illuminate\Support\Facades\Cache;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F9.4 — monthly API-call and automation-run limits from the plan. */
class UsageMeterTest extends TestCase
{
    use IsolatesDatabase;

    private function planWith(array $limits): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => array_merge($plan->limits['modules'] ?? [], ['api'])], $limits)]);
        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
        Cache::flush();
    }

    private function token(): string
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $plain = $this->postJson('/api/api-tokens', ['name' => 'CI', 'abilities' => ['workspaces.view'], 'can_write' => false])->assertCreated()->json('plaintext');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->flushSession();

        return $plain;
    }

    public function test_api_calls_past_the_monthly_limit_get_a_429_with_retry_after(): void
    {
        $this->planWith(['api_calls_per_month' => 2]);
        $headers = ['Authorization' => 'Bearer '.$this->token()];

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        Cache::flush(); // the meter caches its count for a minute

        $response = $this->withHeaders($headers)->getJson('/api/v1/me')->assertStatus(429)->assertJsonPath('code', 'usage_limit');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_a_plan_without_the_limit_is_unlimited(): void
    {
        $this->planWith(['api_calls_per_month' => null, 'automation_runs_per_month' => null]);
        $headers = ['Authorization' => 'Bearer '.$this->token()];

        foreach (range(1, 4) as $_) {
            $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        }

        $this->assertNull(app(UsageMeter::class)->limit(UsageMeter::API_CALLS));
        $this->assertFalse(app(UsageMeter::class)->exceeded(UsageMeter::API_CALLS));
    }

    public function test_the_usage_endpoint_reports_monthly_usage_and_limits(): void
    {
        $this->planWith(['api_calls_per_month' => 50, 'automation_runs_per_month' => 5]);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $monthly = $this->getJson('/api/my-usage')->assertOk()->json('monthly');

        $this->assertSame(50, $monthly['api_calls']['limit']);
        $this->assertSame(5, $monthly['automation_runs']['limit']);
        $this->assertSame(0, $monthly['automation_runs']['used']);
    }
}
