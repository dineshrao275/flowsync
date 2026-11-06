<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiUsage as UsageRow;
use App\Models\SubscriptionPlan;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\AiUsageService;
use App\Services\Ai\Dto\AiUsage;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use IsolatesDatabase;

    private function asTenant(?int $id): AiUsageService
    {
        app(TenantContext::class)->setTenantId($id);

        return app(AiUsageService::class);
    }

    public function test_the_ai_tables_exist_in_the_right_databases(): void
    {
        $this->connectTenant('acme');
        foreach (['ai_conversations', 'ai_messages', 'ai_documents', 'ai_proposals'] as $table) {
            $this->assertTrue(Schema::connection('tenant')->hasTable($table) || Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::connection(config('tenancy.system.connection'))->hasTable('ai_usage'));
    }

    public function test_usage_is_counted_per_tenant_and_day_without_any_text(): void
    {
        $usage = $this->asTenant($this->acme()->id);
        $usage->record(new AiUsage(100, 40), 'gemini-3.1-flash-lite');
        $usage->record(new AiUsage(10, 5), 'gemini-3.1-flash-lite', failed: true);

        $row = UsageRow::where('tenant_id', $this->acme()->id)->sole();
        $this->assertSame(2, $row->requests);
        $this->assertSame(110, $row->input_tokens);
        $this->assertSame(45, $row->output_tokens);
        $this->assertSame(1, $row->failures);
        $this->assertGreaterThan(0, $row->cost_estimate);
        $this->assertNull(UsageRow::where('tenant_id', $this->globex()->id)->first());
    }

    public function test_the_platform_default_limit_applies_when_the_tenant_has_no_plan_limit(): void
    {
        config(['ai.daily_request_limit' => 2]);
        $usage = $this->asTenant($this->acme()->id);

        $usage->record(new AiUsage(1, 1), 'm');
        $usage->assertWithinLimits();
        $usage->record(new AiUsage(1, 1), 'm');

        $this->expectException(AiLimitExceededException::class);
        $usage->assertWithinLimits();
    }

    public function test_the_token_limit_stops_further_requests(): void
    {
        config(['ai.daily_token_limit' => 100]);
        $usage = $this->asTenant($this->acme()->id);
        $usage->record(new AiUsage(80, 30), 'm');

        try {
            $usage->assertWithinLimits();
            $this->fail('Token limit should trigger.');
        } catch (AiLimitExceededException $e) {
            $this->assertSame('token', $e->limit);
            $this->assertSame(100, $e->allowed);
        }
    }

    public function test_a_plan_limit_beats_the_platform_default_and_other_tenants_are_unaffected(): void
    {
        config(['ai.daily_request_limit' => 1000]);
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['ai_requests_per_day' => 1])]);
        app(SubscriptionService::class)->assign($this->acme(), $plan);

        $acme = $this->asTenant($this->acme()->id);
        $acme->record(new AiUsage(1, 1), 'm');

        try {
            $acme->assertWithinLimits();
            $this->fail('The plan limit of 1 should apply.');
        } catch (AiLimitExceededException $e) {
            $this->assertSame(1, $e->allowed);
        }

        $this->asTenant($this->globex()->id)->assertWithinLimits();
        $this->addToAssertionCount(1);
    }

    public function test_there_is_nothing_to_count_or_limit_outside_a_tenant(): void
    {
        $usage = $this->asTenant(null);
        $usage->record(new AiUsage(5, 5), 'm');
        $usage->assertWithinLimits();

        $this->assertSame(0, UsageRow::count());
    }

    public function test_the_snapshot_reports_use_and_limits(): void
    {
        config(['ai.daily_request_limit' => 50, 'ai.daily_token_limit' => 1000]);
        $usage = $this->asTenant($this->acme()->id);
        $usage->record(new AiUsage(30, 20), 'm');

        $this->assertSame(['requests' => 1, 'tokens' => 50, 'requests_limit' => 50, 'tokens_limit' => 1000], $usage->snapshot());
    }

    public function test_an_unpriced_model_costs_nothing_and_a_priced_one_scales_with_tokens(): void
    {
        $usage = app(AiUsageService::class);

        $this->assertSame(0.0, $usage->cost('mystery-model', new AiUsage(1_000_000, 1_000_000)));
        $this->assertEqualsWithDelta(0.15 + 0.60, $usage->cost('openai/gpt-oss-120b', new AiUsage(1_000_000, 1_000_000)), 1e-9);
    }
}
