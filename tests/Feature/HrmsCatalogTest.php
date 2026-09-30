<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The HRMS starter catalogues must match the tables they seed.
 *
 * This exists because of a defect that bit twice. `TenantProvisioner::seedOrgCatalogs()`
 * failed *every tenant's* provisioning with "table locations has no column named
 * code": the catalogue had carried `'code' => 'head_office'` since the phase that
 * introduced it, unconsumed, and `locations` was the one org table given no
 * `code`. Months later `config('hrms.document_types')` carried the same class of
 * bug — `code`, `is_confidential` and `validity_months`, none of them columns,
 * while the `category` column the migration required was absent from the
 * catalogue entirely.
 *
 * **A config key that maps to no column sits unreported until the first thing
 * that reads it**, and the first thing that reads it is
 * `provisionHrmsDefaults()` — which runs inside tenant provisioning, so the
 * failure mode is "every tenant in the fleet fails to provision", not "the
 * document catalogue is wrong". Both times the whole tree had to be repaired
 * with `tenants:provision`. This test is the cheap gate that turns a fleet-wide
 * provisioning outage into a red test on the commit that introduced the key.
 *
 * It asserts both directions, because either alone is insufficient:
 *
 *   - **Every catalogue key is a real column.** Catches a key invented for a
 *     table that never grew it.
 *   - **Every required column is a real catalogue key.** Catches the inverse —
 *     a non-nullable column the seeder would silently omit, leaving the row to
 *     fail at insert time inside provisioning.
 *
 * Only catalogues that are *seeded today* are checked. The rest
 * (`holidays`, `shift_patterns`,
 * `statutory_configurations`) describe tables their phases have not created
 * (or, for configurations, rows no seeder may invent) yet; adding them to
 * the list before the migration lands would make this test fail for a table
 * that is not supposed to exist, which trains the reader to ignore it.
 */
class HrmsCatalogTest extends TestCase
{
    use IsolatesDatabase;

    /**
     * Catalogue key in `config/hrms.php` => the table its seeder writes to.
     *
     * Each entry is added in the same commit that creates the **seeder**, not
     * merely the table — a catalogue nobody seeds is a guess, and listing one
     * here before its `seed*()` method exists would make this test assert a
     * provisioning step that is not there. `document_types` is deliberately
     * absent: P13.1 ships the table and the corrected catalogue, and the seeder
     * and its `DocumentType` model land with the P13.2 service. It joins this
     * list in that commit.
     *
     * @var array<string, string>
     */
    private const SEEDED = [
        'employment_types' => 'employment_types',
        'departments' => 'departments',
        'designations' => 'designations',
        'locations' => 'locations',
        'document_types' => 'document_types',
        'leave_types' => 'leave_types',
        'salary_components' => 'salary_components',
        'expense_categories' => 'expense_categories',
    ];

    /**
     * Required columns the **seeder** supplies rather than the catalogue, with
     * the derivation named so the exemption can be checked by reading one line
     * of `TenantProvisioner` instead of trusting this list.
     *
     * A required column the seeder does not derive is a real bug, but a required
     * column it *does* derive is the established pattern and not a defect: three
     * of the five catalogues key on `code` or `name` and let the seeder mint the
     * `slug` the unique index is on. Exempting them by name is honest; exempting
     * the *column* `slug` wholesale would be not, because a future `slug` with no
     * derivation behind it would then sail through.
     *
     * @var array<string, array<string, string>> catalogue => column => where it comes from
     */
    private const SEEDER_DERIVES = [
        'employment_types' => ['slug' => 'Str::slug($type[\'name\'])'],
        'departments' => ['slug' => '$department[\'code\']'],
        'designations' => ['slug' => '$designation[\'code\']'],
        'leave_types' => ['slug' => 'Str::slug($type[\'name\'])'],
        'salary_components' => ['slug' => 'Str::slug($component[\'name\'])'],
    ];

    public static function seededProvider(): array
    {
        return array_map(fn ($key) => [$key], array_keys(self::SEEDED));
    }

    #[DataProvider('seededProvider')]
    public function test_every_catalogue_key_is_a_real_column(string $catalogue): void
    {
        $table = self::SEEDED[$catalogue];
        $columns = $this->columnNames($table);
        $unknown = [];

        foreach ((array) config("hrms.{$catalogue}") as $entry) {
            foreach (array_keys($entry) as $key) {
                if (! in_array($key, $columns, true)) {
                    $unknown[] = "{$table}.{$key}";
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unknown)),
            "config(hrms.{$catalogue}) names columns that {$table} does not have. A seeder would "
                .'fail inside tenant provisioning, so every tenant in the fleet fails, not one.',
        );
    }

    #[DataProvider('seededProvider')]
    public function test_every_required_column_is_present_in_the_catalogue(string $catalogue): void
    {
        $table = self::SEEDED[$catalogue];
        $required = array_diff($this->requiredColumns($table), array_keys(self::SEEDER_DERIVES[$catalogue] ?? []));
        $missing = [];

        foreach ((array) config("hrms.{$catalogue}") as $entry) {
            foreach ($required as $column) {
                if (! array_key_exists($column, $entry)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            "{$table} has non-nullable, defaultless columns the {$catalogue} catalogue never sets, so the "
                .'seeder row would fail to insert inside tenant provisioning.',
        );
    }

    /**
     * The natural key has to be in the catalogue, because `firstOrCreate()` is
     * what makes provisioning idempotent and a tenant's own renaming repairable.
     * A catalogue that identified its rows by `$index` would re-insert on every
     * `tenants:provision` and duplicate the whole starter set.
     */
    public function test_each_catalogue_names_its_own_natural_key(): void
    {
        $keys = [
            'employment_types' => 'code',
            'departments' => 'code',
            'designations' => 'code',
            'locations' => 'slug',
            'document_types' => 'slug',
            'leave_types' => 'code',
            'salary_components' => 'code',
            'expense_categories' => 'slug',
        ];

        foreach ($keys as $catalogue => $key) {
            foreach ((array) config("hrms.{$catalogue}") as $entry) {
                $this->assertArrayHasKey(
                    $key,
                    $entry,
                    "config(hrms.{$catalogue}) has an entry with no '{$key}' to firstOrCreate() on.",
                );
            }
        }
    }

    public function test_the_document_catalogue_declares_a_category_for_every_type(): void
    {
        // Called out separately from the generic required-column check because
        // `category` is the field the whole compliance story rests on — a
        // document with no family is a document no report can find — and a
        // future editor adding a type is far likelier to omit it than `name`.
        foreach ((array) config('hrms.document_types') as $entry) {
            $this->assertNotEmpty(
                $entry['category'] ?? null,
                "Document type '{$entry['name']}' has no category, so a compliance report cannot group it.",
            );
        }
    }

    private function columnNames(string $table): array
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");

        return array_column(Schema::getColumns($table), 'name');
    }

    /**
     * A column the seeder must set: not nullable and no default. `id` and the
     * timestamps are excluded because the schema builder fills them, and a
     * boolean with `default(false)` is optional by construction.
     *
     * **"No default" is spelled two different ways, and getting it wrong makes
     * this guard silently inert.** SQLite introspection *omits* the `default`
     * key entirely for a column that has none, so `($column['default'] ??
     * 'sentinel') === null` evaluates false and every column looks optional —
     * the test passes while checking nothing. PostgreSQL reports the key with a
     * `null` value. So absence and null both have to count.
     */
    private function requiredColumns(string $table): array
    {
        $managed = ['id', 'created_at', 'updated_at', 'deleted_at'];

        return array_values(array_map(
            fn (array $column) => $column['name'],
            array_filter(
                Schema::getColumns($table),
                fn (array $column) => ! in_array($column['name'], $managed, true)
                    && ($column['nullable'] ?? true) === false
                    && (! array_key_exists('default', $column) || $column['default'] === null),
            ),
        ));
    }
}
