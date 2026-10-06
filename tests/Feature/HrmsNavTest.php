<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 3 — the HRMS sidebar is ONE entry, every feature is a sub-tab of the
 * hub.
 *
 * Before this phase the sidebar carried ~30 flat HRMS links that had to be
 * kept in step with App.jsx by hand. The hub (`HrmsLayout` + `utils/hrmsNav.js`)
 * makes that step testable instead of conventional:
 *
 *   - every `/hrms` route the router owns is a tab, and every tab is a route
 *     (a feature page nobody can reach, or a tab that 404s, fails);
 *   - a tab claims exactly the gates its route enforces — inherited group
 *     gates (`<Route element={<ProtectedRoute module="…">}`) as well as inline
 *     ones — so a sub-tab can never offer a route the caller's policy refuses,
 *     or hide one it would have allowed.
 *
 * The gate extraction mirrors App.jsx's indentation-scoped JSX: a group gate
 * applies to every route nested deeper than the `</Route>` that closes it, and
 * an inline gate lives between a route's `path="…"` and the next route.
 */
class HrmsNavTest extends TestCase
{
    /** The hub block runs from the `hrms.core` parent gate to the `:section` catch-all. */
    private function hubBlock(): string
    {
        $src = file_get_contents(resource_path('js/App.jsx'));

        $layout = strpos($src, '<Route path="/hrms" element={<HrmsLayout />}>');
        $this->assertNotFalse($layout, 'App.jsx no longer renders HrmsLayout at /hrms.');

        $parent = strrpos(substr($src, 0, $layout), '<Route element={<ProtectedRoute module="hrms.core" />}>');
        $this->assertNotFalse($parent, 'the /hrms hub is no longer wrapped in the hrms.core module gate.');

        $catchAll = strpos($src, '<Route path="/hrms/:section"', $layout);
        $this->assertNotFalse($catchAll, 'the /hrms/:section catch-all is gone — unknown sections must still land on the overview.');
        $close = strpos($src, '</Route>', $catchAll);
        $this->assertNotFalse($close);

        return substr($src, $parent, $close - $parent);
    }

    /**
     * Tabs in catalog order: `to` => capabilities (prefixes intact).
     *
     * @return array<string, array<int, string>>
     */
    private function navTabs(): array
    {
        $js = file_get_contents(resource_path('js/utils/hrmsNav.js'));

        $count = preg_match_all(
            "/to: '([^']+)',\s*label: '[^']+',\s*group: '[^']+',\s*capabilities: \[([^\]]*)\]/",
            $js,
            $matches
        );

        $this->assertGreaterThan(0, $count, 'hrmsNav.js no longer parses — keep each entry on one line.');

        $tabs = [];
        foreach ($matches[1] as $i => $to) {
            preg_match_all("/'([^']+)'/", $matches[2][$i], $caps);
            $tabs[$to] = $caps[1];
        }

        return $tabs;
    }

    /**
     * The gates each `/hrms` route actually enforces, without the `module:` /
     * `permission:` prefixes the nav spells them with.
     *
     * @return array<string, array<int, string>>
     */
    private function routeGates(): array
    {
        $block = $this->hubBlock();
        $lines = preg_split('/\r\n|\r|\n/', $block);

        $gates = [];
        /** @var array<int, array{0:int,1:string|null}> $stack groups open above the cursor */
        $stack = [];

        foreach ($lines as $index => $line) {
            $trim = trim($line);
            $indent = strlen($line) - strlen(ltrim($line));

            if (str_starts_with($trim, '<Route element')) {
                if (preg_match('/<Route element=\{<ProtectedRoute (?:module|permission)="([^"]+)"\s*\/>\}>/', $trim, $m)) {
                    $stack[] = [$indent, $m[1]];
                }

                continue;
            }

            if (str_starts_with($trim, '</Route')) {
                while ($stack !== [] && end($stack)[0] >= $indent) {
                    array_pop($stack);
                }

                continue;
            }

            if (! preg_match('/path="(\/hrms[^"]*)"/', $line, $m)) {
                continue;
            }

            $path = $m[1];
            $own = substr($line, strpos($line, $path) + strlen($path));
            $collected = $this->gateNames($own);

            foreach ($stack as $open) {
                if ($open[1] !== null) {
                    $collected[] = $open[1];
                }
            }

            if (str_contains($line, 'element={')) {
                // A one-liner (self-closing or the layout opening children) —
                // nothing below this line belongs to it.
                if (str_ends_with($trim, '}>')) {
                    $stack[] = [$indent, null];
                }
            } else {
                // Multi-line route: the gates sit on the following lines, up to
                // the next route/comment sibling at this indent or shallower.
                for ($j = $index + 1; $j < count($lines); $j++) {
                    $next = trim($lines[$j]);
                    if ($next === '' || str_starts_with($next, '<Route') || str_starts_with($next, '</Route') || str_starts_with($next, '{/*')) {
                        break;
                    }
                    $collected = array_merge($collected, $this->gateNames($lines[$j]));
                }
            }

            $gates[$path] = array_values(array_unique($collected));
        }

        ksort($gates);

        return $gates;
    }

    /**
     * The `module="x"` / `permission="y"` gates named in a line.
     *
     * @return array<int, string>
     */
    private function gateNames(string $line): array
    {
        preg_match_all('/(?:module|permission)="([^"]+)"/', $line, $matches);

        return $matches[1];
    }

    /** The `/hrms*` links the primary sidebar offers. @return array<int, string> */
    private function sidebarLinks(): array
    {
        preg_match_all(
            "/to: '([^']*\/hrms[^']*)'/",
            file_get_contents(resource_path('js/components/Sidebar.jsx')),
            $matches
        );

        return $matches[1];
    }

    public function test_the_sidebar_exposes_a_single_hrms_entry(): void
    {
        $this->assertSame(
            ['/hrms'],
            $this->sidebarLinks(),
            'The People section must carry ONE entry that opens the hub; feature pages are sub-tabs of it.'
        );
    }

    public function test_every_hrms_route_is_a_hub_tab_and_vice_versa(): void
    {
        $tabs = array_keys($this->navTabs());
        $routes = array_keys($this->routeGates());

        // Detail routes (`/hrms/employees/7`) are opened FROM a tab, so they
        // are deliberately not tabs themselves.
        $pages = array_values(array_filter($routes, fn (string $path) => ! str_contains($path, ':')));

        $this->assertSame(
            [],
            array_values(array_diff($pages, $tabs)),
            'A routed HRMS page with no hub tab can only be reached by a hand-typed URL.'
        );
        $this->assertSame(
            [],
            array_values(array_diff($tabs, $pages)),
            'A hub tab whose route is gone is a dead entry in the rail.'
        );
    }

    public function test_every_hub_tab_claims_exactly_the_gates_its_route_enforces(): void
    {
        $gates = $this->routeGates();
        $failures = [];

        foreach ($this->navTabs() as $to => $capabilities) {
            $claimed = array_map(
                fn (string $capability) => preg_replace('/^(module|permission):/', '', $capability),
                $capabilities
            );
            $claimed = array_values(array_unique($claimed));
            sort($claimed);

            $enforced = $gates[$to] ?? null;
            if ($enforced === null) {
                $failures[] = "{$to}: no such route";

                continue;
            }
            sort($enforced);

            if ($claimed !== $enforced) {
                $failures[] = sprintf(
                    '%s: tab claims [%s] but the route enforces [%s]',
                    $to,
                    implode(', ', $claimed),
                    implode(', ', $enforced)
                );
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_the_hub_layout_renders_the_index_and_reads_the_nav_catalog(): void
    {
        $app = file_get_contents(resource_path('js/App.jsx'));

        $this->assertMatchesRegularExpression(
            '#<Route element=\{<ProtectedRoute module="hrms\.core" />\}>\s*<Route path="/hrms" element=\{<HrmsLayout />\}>\s*<Route index element=\{<HrmsOverview />\}\s*/>#',
            $app,
            '/hrms must render HrmsLayout (the rail) with the overview as its index route.'
        );

        $layout = file_get_contents(resource_path('js/components/hrms/HrmsLayout.jsx'));

        $this->assertStringContainsString('hrmsNavGroups', $layout);
        $this->assertStringContainsString('<Outlet />', $layout);
        $this->assertStringContainsString('activeHrmsTab', $layout);

        $nav = file_get_contents(resource_path('js/utils/hrmsNav.js'));
        foreach ($this->sidebarLinks() as $to) {
            $this->assertStringContainsString("to: '{$to}'", $nav, "The sidebar's {$to} entry has no hub tab.");
        }
    }
}
