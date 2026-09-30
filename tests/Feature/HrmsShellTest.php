<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P1.11 — the HRMS shell.
 *
 * The sidebar entry and the `/hrms` route are both gated on `hrms.core`, so the
 * entitlement is enforced twice: once client-side so an unentitled tenant never
 * sees the section, and once server-side by `ensure_module` so a hand-typed URL
 * cannot bypass it.
 *
 * This class also carries the project's delivery gate, because the HRMS plan is
 * executed one task per commit:
 *
 *   - every task is reviewed and verified (full suite, Pint, build) before it is
 *     committed;
 *   - every commit is pushed immediately, without waiting to be asked.
 *
 * `test_every_commit_is_pushed` and `test_the_hrms_tree_is_free_of_debug_leftovers`
 * below enforce the second half of that contract so a forgotten push or a
 * leftover `dd()` fails the suite instead of surviving to review. Both skip
 * themselves when the environment cannot answer the question (no git, no
 * upstream, an exported tarball) rather than failing for the wrong reason.
 */
class HrmsShellTest extends TestCase
{
    use IsolatesDatabase;

    /** @var array<int, string> */
    private const HRMS_PATHS = [
        'app/Enums/Hrms',
        'app/Models/Hrms',
        'app/Services/Hrms',
        'app/Support/Hrms',
        'app/Http/Controllers/Hrms',
        'app/Http/Requests/Hrms',
        'app/Policies/Hrms',
        'app/Services/HrmsAuditLogger.php',
        'config/hrms.php',
        'resources/js/pages/hrms',
        'resources/js/components/hrms',
        'resources/js/utils/hrmsModules.js',
    ];

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_a_tenant_without_the_module_has_no_hrms_sections(): void
    {
        $this->assignPlan('starter');
        $this->login('admin@flowsync.test');

        $modules = $this->getJson('/api/auth/me')->json('user.modules');

        $this->assertNotContains('hrms.core', $modules);
    }

    public function test_a_pro_tenant_gets_the_hrms_modules(): void
    {
        $this->assignPlan('pro');
        $this->login('admin@flowsync.test');

        $modules = $this->getJson('/api/auth/me')->json('user.modules');

        $this->assertContains('hrms.core', $modules);
    }

    public function test_a_tenant_with_no_subscription_is_unlimited_and_keeps_hrms(): void
    {
        $this->login('admin@flowsync.test');

        $modules = $this->getJson('/api/auth/me')->json('user.modules');

        // Seeded acme has no subscription, so it is unlimited — HRMS included,
        // or the demo tenants would lose the whole module.
        $this->assertContains('hrms.core', $modules);
    }

    public function test_every_hrms_permission_is_ungated_for_an_unlimited_tenant(): void
    {
        $this->login('admin@flowsync.test');

        $me = $this->getJson('/api/auth/me')->assertOk();

        // A tenant admin holds `*`, so the whole HRMS permission catalog.
        foreach (array_column(config('permissions.permissions'), 'slug') as $slug) {
            if (str_starts_with($slug, 'hrms.')) {
                $this->assertContains($slug, $me->json('user.permissions'), "Missing {$slug}");
            }
        }
    }

    public function test_the_sidebar_and_route_gate_share_one_module_key(): void
    {
        // Sidebar.jsx gates the People > HRMS item and App.jsx wraps /hrms in
        // ProtectedRoute module="hrms.core". A mismatch would show an entry that
        // leads to a 403, or hide a route that works.
        $sidebar = file_get_contents(resource_path('js/components/Sidebar.jsx'));
        $app = file_get_contents(resource_path('js/App.jsx'));

        $this->assertStringContainsString("capabilities: ['module:hrms.core']", $sidebar);
        $this->assertStringContainsString('<Route path="/hrms"', $app);
        $this->assertStringContainsString('module="hrms.core"', $app);
    }

    public function test_the_spa_catalog_matches_the_php_module_catalog(): void
    {
        // HRMS_MODULE_META is generated from config/subscriptions.php; without
        // this the overview would render a blank tile for any module added to
        // the catalog and nobody would notice until a user hit it.
        $js = file_get_contents(resource_path('js/utils/hrmsModules.js'));

        preg_match_all("/'(hrms\.[a-z_.]+)':\s*\{\s*label:\s*'([^']*)',\s*group:\s*'([^']*)'\s*\}/", $js, $matches, PREG_SET_ORDER);

        $fromJs = [];
        foreach ($matches as [, $key, $label, $group]) {
            $fromJs[$key] = ['label' => $label, 'group' => $group];
        }

        $fromConfig = array_filter(
            config('subscriptions.module_meta'),
            fn (array $meta, string $key) => str_starts_with($key, 'hrms.'),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertCount(count($fromConfig), $fromJs, 'JS and PHP module catalogs have drifted');

        foreach ($fromConfig as $key => $meta) {
            $this->assertArrayHasKey($key, $fromJs, "Missing JS entry for {$key}");
            $this->assertSame($meta['label'], $fromJs[$key]['label'], "Label drift for {$key}");
            $this->assertSame($meta['group'], $fromJs[$key]['group'], "Group drift for {$key}");
        }
    }

    public function test_the_hrms_url_helper_shape(): void
    {
        // hrmsUrl() is the single deep-link builder for HRMS (D2.15).
        $js = file_get_contents(resource_path('js/utils/deepLinks.js'));

        $this->assertStringContainsString('export function hrmsUrl(', $js);
        $this->assertStringContainsString('/hrms/${section}', $js);
    }

    public function test_the_notification_helpers_cover_onboarding_nudges(): void
    {
        // There is no JS test runner, so the toast copy and the deep link for
        // the one notification this phase owns are pinned by string: a nudge
        // that renders “You have a new notification” or lands on `/` is a
        // notification nobody can act on.
        $js = file_get_contents(resource_path('js/utils/notifications.js'));

        $this->assertStringContainsString('hrms.onboarding.task_due', $js);
        $this->assertStringContainsString('onboarding_case_id', $js);
        $this->assertStringContainsString('/hrms/onboarding/cases/${onboarding_case_id}', $js);
    }

    public function test_the_notification_helpers_cover_regularization_nudges(): void
    {
        // Same rule as the onboarding pin above: a correction nudge that
        // renders "You have a new notification" or lands on `/` is one
        // nobody can act on. The ask lands on the review queue, its decision
        // on the month the decision changed.
        $js = file_get_contents(resource_path('js/utils/notifications.js'));

        $this->assertStringContainsString('hrms.attendance.regularization.requested', $js);
        $this->assertStringContainsString('hrms.attendance.regularization.decided', $js);
        $this->assertStringContainsString("'/hrms/attendance/approvals'", $js);
        $this->assertStringContainsString("return '/hrms/attendance';", $js);
    }

    public function test_the_notification_helpers_cover_leave_nudges(): void
    {
        // Same rule as the onboarding and regularization pins: an ask lands
        // on the page that shows it — the admin queue for the approver, the
        // self-service history for the requester.
        $js = file_get_contents(resource_path('js/utils/notifications.js'));

        $this->assertStringContainsString('hrms.leave.requested', $js);
        $this->assertStringContainsString('hrms.leave.approved', $js);
        $this->assertStringContainsString('hrms.leave.rejected', $js);
        $this->assertStringContainsString("return '/hrms/leave';", $js);
        $this->assertStringContainsString("return '/hrms/leave/mine';", $js);
    }

    public function test_the_notification_helpers_cover_comp_off_nudges(): void
    {
        // Same rule as the leave pin: the ask lands on the page that shows
        // it — the hub for the approver, the self-service bank for the
        // requester.
        $js = file_get_contents(resource_path('js/utils/notifications.js'));

        $this->assertStringContainsString('hrms.comp_off.requested', $js);
        $this->assertStringContainsString('hrms.comp_off.approved', $js);
        $this->assertStringContainsString('hrms.comp_off.rejected', $js);
        $this->assertStringContainsString("return '/hrms/comp-off';", $js);
        $this->assertStringContainsString("return '/hrms/comp-off/mine';", $js);
    }

    public function test_the_notification_helpers_cover_expense_nudges(): void
    {
        // Same rule as the leave pin: the filing lands on the queue for the
        // approver, every later step on the claimant's own history — a toast
        // without a claim number or a link to `/` is a nudge nobody can act
        // on.
        $js = file_get_contents(resource_path('js/utils/notifications.js'));

        $this->assertStringContainsString('hrms.expense.submitted', $js);
        $this->assertStringContainsString('hrms.expense.approved', $js);
        $this->assertStringContainsString('hrms.expense.rejected', $js);
        $this->assertStringContainsString('hrms.expense.paid', $js);
        $this->assertStringContainsString('claim_number', $js);
        $this->assertStringContainsString("return '/hrms/expenses';", $js);
        $this->assertStringContainsString("return '/hrms/expenses/mine';", $js);
    }

    public function test_the_notification_helpers_cover_performance_nudges(): void
    {
        // Same rule as every pin before it: a completion lands on the cycle
        // it sealed, an acknowledgement on the self-service page — each
        // where the reader can see what changed.
        $js = file_get_contents(resource_path('js/utils/notifications.js'));

        $this->assertStringContainsString('hrms.performance.cycle_completed', $js);
        $this->assertStringContainsString('hrms.performance.review_acknowledged', $js);
        $this->assertStringContainsString('performance_cycle_id', $js);
        $this->assertStringContainsString('/hrms/performance/cycles/${data.performance_cycle_id}', $js);
        $this->assertStringContainsString("return '/hrms/performance/mine';", $js);
    }

    public function test_hrms_tiles_only_link_to_sections_the_router_owns(): void
    {
        // P2.5 populated HRMS_MODULE_ROUTES with the employee directory, so this
        // is now the stronger claim: every route the catalog advertises must be
        // a path the SPA actually owns. A tile that links to a URL the router
        // does not know lands the user on a blank screen, which reads as a bug
        // rather than as "not built yet".
        $js = file_get_contents(resource_path('js/utils/hrmsModules.js'));
        $page = file_get_contents(resource_path('js/pages/hrms/HrmsOverview.jsx'));
        $app = file_get_contents(resource_path('js/App.jsx'));

        $this->assertStringContainsString('HRMS_MODULE_ROUTES', $js);
        $this->assertStringContainsString('item.to', $page);

        // The inert-tile path stays: a module whose phase has not landed still
        // renders as a non-interactive tile.
        $this->assertStringContainsString('Planned', $page);

        preg_match_all("/'hrms\\.[a-z_.]+': hrmsUrl\\('([a-z-]+)'\\)/", $js, $matches);

        $this->assertNotEmpty($matches[1], 'No module route was found to check.');

        foreach ($matches[1] as $section) {
            $this->assertStringContainsString(
                'path="/hrms/'.$section.'"',
                $app,
                "HRMS_MODULE_ROUTES advertises /hrms/{$section} but the router has no such route.",
            );
        }
    }

    private function assignPlan(string $slug): void
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();

        app(SubscriptionService::class)->assign($this->acme(), $plan);
    }

    // ------------------------------------------------------- delivery gate

    /**
     * A commit that never reached the remote is invisible to everyone else and
     * is the one delivery mistake the "commit, then push" rule exists to
     * prevent.
     *
     * Checks HEAD against the upstream branch only, not the working tree, so the
     * suite stays green while a task is still being edited and only goes red
     * when a commit was actually left behind.
     */
    public function test_every_commit_is_pushed(): void
    {
        if (! $this->gitAvailable()) {
            $this->markTestSkipped('git is not available in this environment');
        }

        // Trimmed: shell_exec keeps the trailing newline, and rev-parse does not
        // strip it from an argument, so an untrimmed branch name is echoed back
        // verbatim instead of being resolved to a SHA.
        $upstream = trim((string) $this->git(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']));

        if ($upstream === '') {
            $this->markTestSkipped('No upstream branch configured');
        }

        $head = trim((string) $this->git(['rev-parse', 'HEAD']));
        $pushed = trim((string) $this->git(['rev-parse', $upstream]));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $head, 'Could not resolve HEAD');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            $pushed,
            "Could not resolve {$upstream} to a commit",
        );
        $this->assertSame(
            $pushed,
            $head,
            "HEAD is not pushed to {$upstream}. Commit then push — do not leave a local-only commit.",
        );
    }

    /**
     * No debug output, TODO or marker comments left in the HRMS tree.
     *
     * A `dd()` that prints an employee's record into the test output, or a
     * console.log left in a page, is exactly what the review step is supposed
     * to catch — and it is far cheaper to catch it here than in review.
     */
    public function test_the_hrms_tree_is_free_of_debug_leftovers(): void
    {
        $offenders = [];

        foreach ($this->hrmsSourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            // Line comments only, so a `dd(` inside a string literal — a mask
            // pattern, a payload shape — is not a false positive.
            $code = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $contents);
            $code = (string) preg_replace('#^\s*\*[^\n]*$#m', '', $code);

            // A word boundary is required: `ray(` is a substring of `array(`
            // and would flag every typed property in the tree.
            foreach (['dd', 'dump', 'var_dump', 'print_r', 'ray', 'console.log'] as $needle) {
                if (preg_match('/(?<![A-Za-z0-9_$.>])'.preg_quote($needle, '/').'\s*\(/', $code)) {
                    $offenders[] = basename($file).': '.$needle.'()';
                }
            }

            foreach (['TODO', 'FIXME', 'XXX', 'APPEND'] as $marker) {
                if (preg_match('/\b'.preg_quote($marker, '/').'\b/', $code)) {
                    $offenders[] = basename($file).': '.$marker;
                }
            }
        }

        $this->assertSame([], $offenders, 'Debug leftovers found: '.implode(', ', $offenders));
    }

    /**
     * The HRMS source files the delivery gate inspects.
     *
     * @return array<int, string>
     */
    private function hrmsSourceFiles(): array
    {
        $files = [];

        foreach (self::HRMS_PATHS as $path) {
            $full = base_path($path);

            if (is_file($full)) {
                $files[] = $full;

                continue;
            }

            if (! is_dir($full)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full));

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'jsx'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertNotSame([], $files, 'No HRMS source files were inspected');

        return $files;
    }

    private function gitAvailable(): bool
    {
        // shell_exec keeps the trailing newline, so the comparison must trim or
        // the guard silently skips instead of running.
        return trim((string) $this->git(['rev-parse', '--is-inside-work-tree'])) === 'true';
    }

    /**
     * @param  array<int, string>  $args
     */
    private function git(array $args): ?string
    {
        $command = 'git '.implode(' ', array_map('escapeshellarg', $args)).' 2>/dev/null';

        return shell_exec($command);
    }
}
