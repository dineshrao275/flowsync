<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Hrms\Survey\SurveyCampaign;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hrms\InboxService;
use App\Services\Hrms\MyHrService;
use App\Services\Hrms\Survey\SurveyResults;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The cache lives in the shared system database in production and ids are tenant-local, so a key
 * built from `user->id` alone serves tenant A's payload to tenant B's user with the same id.
 */
class HrmsCacheTenantKeyTest extends TestCase
{
    use IsolatesDatabase;

    private function enterTenant(string $slug): int
    {
        $this->connectTenant($slug);
        $id = (int) Tenant::where('slug', $slug)->firstOrFail()->id;
        app(TenantContext::class)->setTenantId($id);

        return $id;
    }

    public function test_the_inbox_unread_count_is_cached_per_tenant(): void
    {
        $tenantId = $this->enterTenant('acme');
        $user = User::firstOrFail();

        app(InboxService::class)->unreadCount($user);

        $this->assertTrue(Cache::has("hrms.inbox.unread.t{$tenantId}.{$user->id}"));
        $this->assertFalse(Cache::has("hrms.inbox.unread.{$user->id}"));
    }

    public function test_the_my_hr_payload_is_cached_per_tenant(): void
    {
        $tenantId = $this->enterTenant('acme');
        $user = User::firstOrFail();

        app(MyHrService::class)->aggregate($user);

        $this->assertTrue(Cache::has("hrms.myhr.t{$tenantId}.{$user->id}"));
        $this->assertFalse(Cache::has("hrms.myhr.{$user->id}"));
    }

    public function test_survey_result_keys_differ_between_tenants(): void
    {
        $campaign = new SurveyCampaign;
        $campaign->id = 7;

        $this->enterTenant('acme');
        $acmeKey = SurveyResults::resultsKey($campaign);
        $this->enterTenant('globex');
        $globexKey = SurveyResults::resultsKey($campaign);

        $this->assertNotSame($acmeKey, $globexKey);
    }
}
