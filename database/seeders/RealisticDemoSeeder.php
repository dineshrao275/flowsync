<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Carbon\CarbonImmutable;
use Database\Seeders\Realistic\Bulk;
use Database\Seeders\Realistic\CentralSeeder;
use Database\Seeders\Realistic\HrmsSeeder;
use Database\Seeders\Realistic\PeopleSeeder;
use Database\Seeders\Realistic\TenantBlueprints;
use Database\Seeders\Realistic\TenantRun;
use Database\Seeders\Realistic\Timeline;
use Database\Seeders\Realistic\TmsSeeder;
use Database\Seeders\Realistic\UsageSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Master seeder for the realistic demo dataset (see config/demo_seed.php and .agents/roadmap/realistic-seed-plan.md).
 *
 * One tenant at a time, each in its own database: create the tenant → provision only the products it subscribes to →
 * subscribe → people → TMS data → HRMS data → usage + lifecycle status. Every random draw is seeded from the tenant's
 * index, so a tenant is identical on every run and the work can be split across processes with --from/--to.
 */
class RealisticDemoSeeder extends Seeder
{
    /** Platform-level demo marker kept in tenants.settings.demo_seed */
    public const MARKER = 'demo_seed';

    public function run(?int $from = null, ?int $to = null, ?string $category = null, array $slugs = [], bool $reseed = false, bool $dryRun = false): void
    {
        $blueprints = TenantBlueprints::all();
        $selected = array_filter($blueprints, function (array $bp) use ($from, $to, $category, $slugs) {
            return ($from === null || $bp['index'] >= $from) && ($to === null || $bp['index'] <= $to)
                && ($category === null || $bp['category'] === $category) && ($slugs === [] || in_array($bp['slug'], $slugs, true));
        });

        $this->say(sprintf('%d of %d tenants selected%s.', count($selected), count($blueprints), $dryRun ? ' (dry run)' : ''));
        if ($dryRun) {
            foreach ($selected as $bp) {
                $this->say(sprintf('#%03d %-8s %-42s %-24s %-9s users=%d', $bp['index'], $bp['category'], $bp['name'], implode('+', $bp['plans']), $bp['state'], $bp['users']));
            }

            return;
        }

        $dbm = app(TenantDatabaseManager::class);
        $dbm->connectSystem();
        $passwordHash = Hash::make((string) config('demo_seed.password'));
        $now = CarbonImmutable::now()->startOfDay()->setTime(10, 0);
        $started = microtime(true);
        $done = $skipped = $failed = 0;

        foreach ($selected as $bp) {
            $t0 = microtime(true);
            try {
                $result = $this->seedTenant($bp, $dbm, $passwordHash, $now, $reseed);
                $result === 'skipped' ? $skipped++ : $done++;
                $this->say(sprintf('#%03d %-44s %s (%.1fs)', $bp['index'], $bp['name'], $result, microtime(true) - $t0));
            } catch (Throwable $e) {
                $failed++;
                Log::error('Realistic seed failed for '.$bp['slug'], ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $this->say(sprintf('#%03d %-44s FAILED: %s', $bp['index'], $bp['name'], $e->getMessage()), true);
                $dbm->connectSystem();
            }
        }

        $this->say(sprintf('Finished in %.1fs — seeded %d, skipped %d, failed %d.', microtime(true) - $started, $done, $skipped, $failed));
    }

    /** @param array<string, mixed> $bp */
    public function seedTenant(array $bp, TenantDatabaseManager $dbm, string $passwordHash, CarbonImmutable $now, bool $reseed): string
    {
        $existing = Tenant::withTrashed()->where('slug', $bp['slug'])->first();
        if ($existing) {
            $marker = $existing->settings[self::MARKER] ?? null;
            if ($marker === null) {
                throw new \RuntimeException("Tenant '{$bp['slug']}' exists and is not a demo tenant; refusing to touch it.");
            }
            $current = ($marker['version'] ?? 0) === (int) config('demo_seed.version') && ($marker['complete'] ?? false);
            if ($current && ! $reseed) {
                return 'skipped';
            }
            $this->destroy($existing, $dbm);
        }

        $tl = Timeline::for($bp, $now);
        $central = new CentralSeeder;
        $tenant = $central->createTenant($bp, $tl);

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class), $bp['products']);
        $tenant->refresh();

        $subs = $central->subscribe($tenant, $bp, $tl, $bp['users']);

        $dbm->using($tenant, function () use ($bp, $tl, $tenant, $passwordHash, $central, $subs): void {
            $bulk = new Bulk;
            $run = new TenantRun($bp, $tl, $tenant, $bulk, $passwordHash);
            $run->refreshEntitlements();

            // people
            $people = new PeopleSeeder($bulk);
            $plan = $people->plan($bp, $tl);
            $owner = $plan['people'][0];
            DB::table('users')->where('id', 1)->update([
                'name' => $owner['name'], 'email' => $owner['email'], 'password' => $passwordHash,
                'email_verified_at' => $tl->created, 'created_at' => $tl->created, 'updated_at' => $tl->created,
            ]);
            $run->people = $people->persist($plan, $bp, $tl, $passwordHash, 1);

            if ($run->hasProduct('tms')) {
                (new TmsSeeder)->seed($run);
            }
            if ($run->hasProduct('hrms')) {
                (new HrmsSeeder)->seed($run);
            }

            $bulk->syncSequences();
            (new UsageSeeder)->seed($run, $subs);

            $central->route($tenant, array_map(fn ($p) => ['id' => $p['user_id'], 'email' => $p['email'], 'name' => $p['name'], 'created_at' => $p['user_created_at']], $run->people));
        });

        $this->finalize($tenant, $bp, $tl);

        return 'seeded';
    }

    /** Move the tenant to its final lifecycle status and stamp the completion marker. */
    private function finalize(Tenant $tenant, array $bp, Timeline $tl): void
    {
        $tenant->refresh();
        $lifecycle = app(TenantLifecycle::class);
        $target = $bp['tenant_status'];
        if ($tenant->status !== $target && $lifecycle->canTransition($tenant, $target)) {
            $lifecycle->transition($tenant, $target, null, ['reason' => 'demo seed: '.$bp['state']]);
        }

        $settings = (array) $tenant->settings;
        $settings[self::MARKER] = ['version' => (int) config('demo_seed.version'), 'category' => $bp['category'], 'state' => $bp['state'], 'complete' => true, 'seeded_at' => now()->toIso8601String()];
        $tenant->forceFill(['settings' => $settings, 'provisioned_at' => $tl->created, 'updated_at' => $tl->end])->saveQuietly();
    }

    /** Remove a demo tenant completely (database, routing, billing rows, tenant row) so it can be rebuilt. */
    private function destroy(Tenant $tenant, TenantDatabaseManager $dbm): void
    {
        $dbm->connectSystem();
        $dbm->dropDatabase($tenant);
        $c = DB::connection(config('tenancy.system.connection'));
        TenantUserRouting::where('tenant_id', $tenant->id)->delete();
        foreach (['invoice_lines' => null, 'invoices' => 'tenant_id', 'payments' => 'tenant_id', 'subscription_events' => 'tenant_id', 'usage_metrics' => 'tenant_id', 'provisioning_runs' => 'tenant_id'] as $table => $col) {
            if ($table === 'invoice_lines') {
                $c->table('invoice_lines')->whereIn('invoice_id', $c->table('invoices')->where('tenant_id', $tenant->id)->select('id'))->delete();
            } else {
                $c->table($table)->where($col, $tenant->id)->delete();
            }
        }
        $c->table('tenants')->where('id', $tenant->id)->update(['subscription_id' => null]);
        $c->table('subscriptions')->where('tenant_id', $tenant->id)->delete();
        $tenant->forceDelete();
    }

    private ?Command $cmd = null;

    public function setCommand($command): static
    {
        $this->cmd = $command;

        return $this;
    }

    private function say(string $line, bool $error = false): void
    {
        $this->cmd ? ($error ? $this->cmd->error($line) : $this->cmd->line($line)) : null;
    }
}
