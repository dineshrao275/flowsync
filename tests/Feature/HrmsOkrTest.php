<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F8 — OKRs: progress comes from the numbers; company objectives need a performance manager. */
class HrmsOkrTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);
        $this->me = $this->userWith(['hrms.view']);
        $this->makeEmployee('Olga Owner', ['user_id' => $this->me->id]);
    }

    private function create(array $over = []): array
    {
        return $this->postJson('/api/hrms/okrs', $over + [
            'scope' => 'mine', 'title' => 'Grow signups', 'period' => 'Q4 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31',
            'key_results' => [['title' => 'Signups', 'start_value' => 100, 'target_value' => 200], ['title' => 'Churn %', 'start_value' => 10, 'target_value' => 5]],
        ])->assertCreated()->json('objective');
    }

    public function test_progress_is_computed_including_targets_below_the_start(): void
    {
        $this->actAs($this->me);
        $o = $this->create();
        $this->assertSame(0, $o['progress']);

        $this->putJson("/api/hrms/okrs/key-results/{$o['key_results'][0]['id']}", ['current_value' => 150])->assertOk();           // 50 %
        $res = $this->putJson("/api/hrms/okrs/key-results/{$o['key_results'][1]['id']}", ['current_value' => 5])->assertOk();    // 100 % (lower is better)
        $this->assertSame(75, $res->json('objective.progress'));

        $this->postJson("/api/hrms/okrs/{$o['id']}/close")->assertOk()->assertJsonPath('objective.status', 'closed');
    }

    public function test_company_objectives_need_a_performance_manager_but_everyone_can_read_them(): void
    {
        $this->actAs($this->me);
        $this->postJson('/api/hrms/okrs', ['scope' => 'company', 'title' => 'Be great', 'period' => 'FY26', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage']));
        $this->postJson('/api/hrms/okrs', ['scope' => 'company', 'title' => 'Be great', 'period' => 'FY26', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated();

        $this->actAs($this->me);
        $this->getJson('/api/hrms/okrs')->assertOk()->assertJsonCount(1, 'objectives')->assertJsonPath('objectives.0.scope', 'company');
    }

    public function test_a_stranger_does_not_see_a_personal_objective(): void
    {
        $this->actAs($this->me);
        $this->create();

        $stranger = $this->userWith(['hrms.view']);
        $this->makeEmployee('Sam Stranger', ['user_id' => $stranger->id]);
        $this->actAs($stranger);
        $this->getJson('/api/hrms/okrs')->assertOk()->assertJsonCount(0, 'objectives');
    }
}
