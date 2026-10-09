<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * R12 — a permission slug typed wrongly in the SPA hides UI silently (`can()`
 * just answers false), and nothing else notices. This reads every permission
 * literal the SPA passes to `can()`, `check()`, `<ProtectedRoute permission>`,
 * `capabilities: [...]` and `hasPermission()` and requires it to be a real
 * catalog slug.
 */
class FrontendPermissionLiteralsTest extends TestCase
{
    private const PATTERNS = [
        '/\bcan\(\s*[\'"]([^\'"]+)[\'"]/',
        '/\bcheck\(\s*[\'"]([^\'"]+)[\'"]/',
        '/\bhasPermission\(\s*[\'"]([^\'"]+)[\'"]/',
        '/permission=["\']([^"\']+)["\']/',
        '/\bpermission:\s*[\'"]([^\'"]+)[\'"]/',
    ];

    public function test_every_permission_literal_in_the_spa_exists_in_the_catalog(): void
    {
        $catalog = array_flip(array_column(config('permissions.permissions'), 'slug'));
        $unknown = [];

        foreach ($this->spaFiles() as $file) {
            $source = file_get_contents($file);

            foreach ($this->literals($source) as $literal) {
                if (! isset($catalog[$literal])) {
                    $unknown[] = $literal.'  ('.str_replace(base_path().'/', '', $file).')';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($unknown)), "SPA references permission slugs that are not in config/permissions.php:\n".implode("\n", array_unique($unknown)));
    }

    public function test_the_scan_actually_finds_literals(): void
    {
        $total = 0;
        foreach ($this->spaFiles() as $file) {
            $total += count($this->literals(file_get_contents($file)));
        }

        $this->assertGreaterThan(20, $total, 'The literal scan matched almost nothing — the patterns have drifted from the code.');
    }

    /** @return list<string> */
    private function literals(string $source): array
    {
        $found = [];

        foreach (self::PATTERNS as $pattern) {
            preg_match_all($pattern, $source, $matches);
            array_push($found, ...$matches[1]);
        }

        // `capabilities: ['module:x', 'billing.view']` arrays.
        preg_match_all('/capabilities:\s*\[([^\]]*)\]/', $source, $arrays);
        foreach ($arrays[1] as $list) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $items);
            array_push($found, ...$items[1]);
        }

        $slugs = [];
        foreach ($found as $value) {
            $value = preg_replace('/^permission:/', '', $value);

            // Module capabilities and anything that is not slug-shaped (a code
            // comment, a template string) are not permission literals.
            if (str_starts_with($value, 'module:') || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $value) !== 1) {
                continue;
            }

            $slugs[] = $value;
        }

        return $slugs;
    }

    /** @return list<string> */
    private function spaFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $file) {
            if (preg_match('/\.jsx?$/', $file->getFilename()) && ! str_contains($file->getFilename(), '.test.')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
