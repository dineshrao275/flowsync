<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F7.10 — the reporting-line chart exposes names and titles only, and never loses anyone. */
class HrmsOrgChartTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    public function test_nodes_link_to_managers_and_orphans_become_roots(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $boss = $this->makeEmployee('Boss');
        $report = $this->makeEmployee('Report', ['manager_id' => $boss->id]);
        $gone = $this->makeEmployee('Gone', ['status' => 'terminated']);
        $orphan = $this->makeEmployee('Orphan', ['manager_id' => $gone->id]);

        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));
        $nodes = collect($this->getJson('/api/hrms/org-chart')->assertOk()->json('nodes'))->keyBy('id');

        $this->assertNull($nodes[$boss->id]['manager_id']);
        $this->assertSame($boss->id, $nodes[$report->id]['manager_id']);
        $this->assertNull($nodes[$orphan->id]['manager_id']);   // its manager is not active, so it is a root, not lost
        $this->assertFalse($nodes->has($gone->id));
        $this->assertArrayNotHasKey('personal_email', $nodes[$boss->id]);
    }

    public function test_it_needs_the_directory_permission(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/org-chart')->assertForbidden();
    }
}
