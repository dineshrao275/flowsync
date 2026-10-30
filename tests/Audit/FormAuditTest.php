<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Services\ReportsTo;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Validator;
use Tests\IsolatesDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Form submission audit (run on demand, never part of the normal suite):
 *
 *   FORM_AUDIT=1 php artisan test tests/Audit/FormAuditTest.php
 *
 * Submits every write endpoint of the application as every role over the real HTTP stack against one realistic
 * tenant, restoring the data after each state-changing request, and records the outcome of each submission as a JSON
 * line in storage/app/form-audit/results.jsonl for the report (see scripts/form-audit-report.py).
 *
 * Optional: AUDIT_ONLY=<regex on "METHOD uri">, AUDIT_ROLES=a,b, AUDIT_LIMIT=<n routes>.
 */
final class FormAuditTest extends TestCase
{
    use IsolatesDatabase;

    private const MAX_ATTEMPTS = 10;

    /** Endpoints that cannot be submitted by a person at a keyboard (signed callbacks, bearer-token API). */
    private const SKIP = [
        '#^api/webhooks/(stripe|razorpay)$#' => 'provider webhook: authenticated by a signature, not a form',
        '#^api/v1/#' => 'bearer-token API (api_token), not a browser form',
        '#^api/sso/#' => 'OIDC redirect/callback, not a form',
        '#^api/integrations/github/#' => 'HMAC-signed webhook, not a form',
        '#^api/csp-report$#' => 'browser-sent CSP report, not a form',
        '#^api/client-log$#' => 'browser error reporter, not a form',
    ];

    /** @var list<array<string, mixed>> rule sets captured from the validator during the current submission */
    private array $captured = [];

    private ?Throwable $lastException = null;

    private bool $dirty = false;

    /** While true, successful submissions are kept (bootstrap) instead of being undone before the next one. */
    private bool $persist = false;

    /** Endpoint names that act on an existing record or the whole tenant, so are not plain "create" forms. */
    private const NOT_CREATE = '#(import|rollover|accrue|bulk|purge|impersonate|logout|login|register|switch|cancel|suspend|reset|restore|sync|recompute|rollup|reopen|start|stop|clear|close|complete|apply|publish|finali[sz]e|lock|unlock|reverse|refund|renew|split|merge|verify|send|resend|mark|read|revoke|disable|enable|toggle|unlink|detach|withdraw|approve|reject|decide|surrender|reveal|move|archive|duplicate|convert|promote|terminate|initiate|pay|calculate|generate|retry|rotate|regenerate|forgot|2fa|two-factor|billing|payment|checkout|subscription|impersonation)#i';

    private const BOOTSTRAP_EXTRA = ['POST api/projects/{project}/save-as-template'];

    private const BOOTSTRAP_ROLES = ['admin', 'employee', 'manager', 'hr_manager', 'hr_executive', 'finance_admin', 'editor', 'project_lead'];

    public function test_every_write_route_for_every_role(): void
    {
        if (! getenv('FORM_AUDIT')) {
            $this->markTestSkipped('On-demand audit: run with FORM_AUDIT=1.');
        }

        set_time_limit(0);
        ini_set('memory_limit', '2G');
        config(['app.debug' => true]);
        Http::preventStrayRequests();
        // The webhook URL guard resolves DNS; answer with a public address so https://example.com/... is acceptable.
        config(['webhooks.resolver' => fn (string $host) => ['93.184.216.34']]);
        Storage::fake('local');
        Storage::fake('public');

        $world = new AuditWorld($this->isoTenantDir, $this->dbm);
        $world->build();
        $this->hookValidator();
        $this->hookExceptions();

        $out = storage_path('app/form-audit');
        File::ensureDirectoryExists($out);
        $resultsFile = $out.'/results.jsonl';
        file_put_contents($resultsFile, '');

        $only = getenv('AUDIT_ONLY') ?: null;
        $roleFilter = getenv('AUDIT_ROLES') ? explode(',', (string) getenv('AUDIT_ROLES')) : null;
        $limit = (int) (getenv('AUDIT_LIMIT') ?: 0);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/') && array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
            ->sortBy(fn (LaravelRoute $r) => $r->uri())
            ->values();

        $fixtures = new RouteParams;
        $builder = new PayloadBuilder(fn (string $table, string $column, int $offset) => $this->lookup($table, $column, $offset));
        $submitted = 0;
        $handled = 0;

        if (! getenv('AUDIT_NO_BOOTSTRAP')) {
            $this->bootstrap($routes, $world, $fixtures, $builder, $resultsFile);
        }
        $world->freeze();
        $this->dirty = false;

        foreach ($routes as $route) {
            $method = array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))[0];
            $key = $method.' '.$route->uri();

            if ($only !== null && ! preg_match('#'.$only.'#', $key)) {
                continue;
            }
            if ($limit > 0 && $handled >= $limit) {
                break;
            }
            $handled++;

            foreach (self::SKIP as $pattern => $reason) {
                if (preg_match($pattern, $route->uri())) {
                    $this->write($resultsFile, ['route' => $key, 'perspective' => '-', 'class' => 'SKIPPED', 'note' => $reason]);

                    continue 2;
                }
            }

            foreach ($this->perspectives($route) as $perspective) {
                if ($roleFilter !== null && ! in_array($perspective, $roleFilter, true)) {
                    continue;
                }
                $this->write($resultsFile, $this->submit($route, $method, $perspective, $world, $fixtures, $builder));
                $submitted++;
            }
        }

        if (! getenv('AUDIT_NO_SCENARIOS')) {
            $this->runScenarios($world, $builder, $resultsFile);
        }

        fwrite(STDERR, sprintf("\nform audit: %d routes, %d submissions -> %s\n", $handled, $submitted, $resultsFile));
        $this->assertGreaterThan(0, $submitted);
    }

    /**
     * Fill the tenant with one record of everything the app lets a person create, by submitting the plain create
     * forms (twice over, so records that need a parent can find it). Kept, not undone: the sweep then has real data
     * to edit, approve and delete for entities the seeder does not produce. These submissions are results too.
     *
     * @param  \Illuminate\Support\Collection<int, LaravelRoute>  $routes
     */
    private function bootstrap($routes, AuditWorld $world, RouteParams $fixtures, PayloadBuilder $builder, string $resultsFile): void
    {
        $this->persist = true;
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $r) => $r->uri())->all();
        // A create form posts to a collection: some other route addresses one member of it (`uri/{id}`).
        $hasMembers = fn (string $uri): bool => (bool) array_filter($uris, fn ($u) => str_starts_with($u, $uri.'/{'));
        $creates = $routes->filter(fn (LaravelRoute $r) => in_array('POST', $r->methods(), true)
            && ! preg_match(self::NOT_CREATE, $r->uri())
            && $hasMembers($r->uri())
            && ! array_filter(array_keys(self::SKIP), fn ($p) => preg_match($p, $r->uri())))
            ->sortBy(fn (LaravelRoute $r) => [count($r->parameterNames()), $r->uri()])->values();

        // Create forms with no member route of their own (so the structural rule above misses them).
        $extra = $routes->filter(fn (LaravelRoute $r) => in_array('POST '.$r->uri(), self::BOOTSTRAP_EXTRA, true));
        $pending = $creates->merge($extra)->unique(fn (LaravelRoute $r) => $r->uri())->values();
        for ($round = 1; $round <= 2 && $pending->isNotEmpty(); $round++) {
            $failed = collect();
            foreach ($pending as $route) {
                $mw = array_filter($route->gatherMiddleware(), 'is_string');
                $platform = (bool) array_filter($mw, fn ($m) => str_starts_with($m, 'super_admin') || str_starts_with($m, 'platform:'));
                $authed = (bool) array_filter($mw, fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:'));
                if (! $authed) {
                    continue;
                }
                $created = false;
                foreach ($platform ? ['super_admin'] : self::BOOTSTRAP_ROLES as $perspective) {
                    $record = $this->submit($route, 'POST', $perspective, $world, $fixtures, $builder);
                    $record['phase'] = 'bootstrap-'.$round;
                    $this->write($resultsFile, $record);
                    if (($record['status'] ?? 0) >= 200 && ($record['status'] ?? 0) < 300) {
                        $created = true;
                        break;
                    }
                }
                if (! $created) {
                    $failed->push($route);
                }
            }
            $pending = $failed;
        }
        $this->persist = false;
        $this->dirty = false;
    }

    /**
     * Submit, and while the validator answers 422 rebuild the payload from the rules it ran and from its messages.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: array<string, mixed>, 2: int, 3: array<string, mixed>}
     */
    private function sendHealed(string $method, string $url, array $forced, PayloadBuilder $builder): array
    {
        $payload = $forced;
        $rules = [];
        $response = null;
        $attempt = 0;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            $this->captured = [];
            $this->lastException = null;
            $response = $this->send($method, $url, $payload);
            $status = $response->getStatusCode();
            if (! $this->persist && ! in_array($status, [401, 403, 404, 405, 422], true)) {
                $this->dirty = true;
            }
            if ($status !== 422) {
                break;
            }
            foreach ($this->captured as $set) {
                $rules = array_merge($rules, $set);
            }
            $next = array_replace_recursive($builder->build($rules, $payload, (array) ($response->json('errors') ?? [])), $forced);
            if ($this->signature($next) === $this->signature($payload)) {
                break;
            }
            $payload = $next;
        }

        return [$response, $payload, $attempt, $rules];
    }

    /** @return array<string, mixed> */
    private function describe(\Illuminate\Testing\TestResponse $response, array $payload, int $attempt, array $rules): array
    {
        $json = $response->json();
        $out = [
            'status' => $response->getStatusCode(),
            'attempts' => $attempt,
            'message' => is_array($json) ? substr((string) ($json['message'] ?? ''), 0, 300) : substr((string) $response->getContent(), 0, 200),
            'errors' => array_slice(array_map(fn ($m) => is_array($m) ? ($m[0] ?? '') : $m, (array) ($json['errors'] ?? [])), 0, 6, true),
            'payload' => substr(json_encode($this->printable($payload)) ?: '', 0, 700),
            'rules_seen' => count($rules),
        ];
        if ($response->getStatusCode() >= 500) {
            $e = $this->lastException;
            $out['exception'] = $e ? get_class($e).': '.substr($e->getMessage(), 0, 400).' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine() : substr((string) ($json['exception'] ?? '').' '.(string) ($json['message'] ?? ''), 0, 400);
        }

        return $out;
    }

    /**
     * One step of a workflow scenario: a real form submission as a chosen person, recorded like any other result.
     * $as is a perspective name, or `user:<id>` for the exact person the workflow designates.
     *
     * @param  array<string, mixed>  $forced
     */
    public function step(string $name, string $as, string $method, string $uri, array $forced = []): \Illuminate\Testing\TestResponse
    {
        $world = $this->scenarioWorld;
        $uri = ltrim($uri, '/');
        $url = '/'.$uri;
        $record = ['phase' => 'scenario:'.$name, 'perspective' => $as];

        $route = null;
        try {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($url, $method));
        } catch (Throwable) {
            // an unknown url is recorded with its literal path below
        }
        $record['route'] = $method.' '.($route?->uri() ?? $uri);
        $record['url'] = $url;

        $userId = str_starts_with($as, 'user:') ? (int) substr($as, 5) : null;
        $this->resetRuntime();
        $this->actAs($userId !== null ? 'employee' : $as, $world, $userId);
        [$response, $payload, $attempt, $rules] = $this->sendHealed($method, $url, $forced, $this->scenarioBuilder);
        $this->write($this->scenarioFile, $record + $this->describe($response, $payload, $attempt, $rules));
        $this->dbm->connect($world->tenant);

        return $response;
    }

    private ?AuditWorld $scenarioWorld = null;

    private ?PayloadBuilder $scenarioBuilder = null;

    private string $scenarioFile = '';

    /** Run each scripted workflow from a clean copy of the world. */
    private function runScenarios(AuditWorld $world, PayloadBuilder $builder, string $resultsFile): void
    {
        Scenarios::bind($world);
        $this->scenarioWorld = $world;
        $this->scenarioBuilder = $builder;
        $this->scenarioFile = $resultsFile;
        $only = getenv('AUDIT_SCENARIO') ?: null;

        foreach (Scenarios::all() as $name => $scenario) {
            if ($only !== null && ! preg_match('#'.$only.'#', $name)) {
                continue;
            }
            $world->restore();
            $this->dirty = true;
            $this->resetRuntime();
            $this->dbm->connect($world->tenant);
            try {
                $scenario($this, $world);
            } catch (Throwable $e) {
                $this->write($resultsFile, ['phase' => 'scenario:'.$name, 'route' => 'SCENARIO '.$name, 'perspective' => '-', 'status' => 0, 'harness_error' => get_class($e).': '.substr($e->getMessage(), 0, 300).' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine()]);
            }
        }
        $world->restore();
        $this->dirty = false;
    }

    /** @return list<string> */
    private function perspectives(LaravelRoute $route): array
    {
        $mw = $route->gatherMiddleware();
        $strings = array_filter($mw, 'is_string');
        $authed = (bool) array_filter($strings, fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:'));
        $platform = (bool) array_filter($strings, fn ($m) => str_starts_with($m, 'super_admin') || str_starts_with($m, 'platform:'));

        if (! $authed) {
            return ['guest'];
        }
        if ($platform) {
            return ['super_admin', 'admin', 'employee', 'guest'];
        }

        // Approval-style actions only make sense for the person the workflow assigns them to, or who asked.
        $acting = (bool) preg_match('#/(approve|reject|decide|withdraw|cancel)$#', $route->uri());

        return ['guest', ...array_keys(AuditWorld::ROLES), ...($acting ? ['approver', 'requester'] : [])];
    }

    /**
     * The user the approval workflow itself designates for a record of this route's model: the assigned approver of
     * its pending step, or whoever requested it. Returns [userId, recordId] or null when nothing is pending.
     *
     * @return array{0: int, 1: int}|null
     */
    private function workflowActor(LaravelRoute $route, string $perspective, RouteParams $fixtures): ?array
    {
        $class = $fixtures->lastModelClass($route);
        if ($class === null || ! Schema::hasTable('approvals')) {
            return null;
        }

        $approvals = DB::table('approvals')->where('status', 'pending')
            ->where(fn ($q) => $q->where('approvable_type', $class)->orWhere('approvable_type', 'like', '%\\'.class_basename($class)))
            ->orderByDesc('id')->get();

        foreach ($approvals as $approval) {
            if ($perspective === 'requester') {
                if ($approval->requested_by_user_id) {
                    return [(int) $approval->requested_by_user_id, (int) $approval->approvable_id];
                }

                continue;
            }
            $step = DB::table('approval_steps')->where('approval_id', $approval->id)->where('status', 'pending')->orderBy('id')->first();
            if ($step === null) {
                continue;
            }
            $userId = $step->approver_user_id
                ?: ($step->approver_role_id ? DB::table('role_user')->where('role_id', $step->approver_role_id)->value('user_id') : null)
                ?: ($step->approver_employee_id ? DB::table('employees')->where('id', $step->approver_employee_id)->value('user_id') : null)
                ?: ($approval->requested_by_employee_id
                    ? DB::table('employees as m')->join('employees as e', 'e.manager_id', '=', 'm.id')->where('e.id', $approval->requested_by_employee_id)->value('m.user_id')
                    : null);
            if ($userId) {
                return [(int) $userId, (int) $approval->approvable_id];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function submit(LaravelRoute $route, string $method, string $perspective, AuditWorld $world, RouteParams $fixtures, PayloadBuilder $builder): array
    {
        $started = microtime(true);
        $record = ['route' => $method.' '.$route->uri(), 'perspective' => $perspective];

        try {
            if ($this->dirty) {
                $world->restore();
                $this->dirty = false;
            }
            $this->resetRuntime();
            $explicit = null;
            $forcedId = null;
            if (in_array($perspective, ['approver', 'requester'], true)) {
                $this->dbm->connect($world->tenant);
                $found = $this->workflowActor($route, $perspective, $fixtures);
                if ($found === null) {
                    return $record + ['status' => 0, 'class' => 'NO_PENDING_APPROVAL', 'note' => 'no pending approval of this kind to act on'];
                }
                [$explicit, $forcedId] = $found;
                $record['acting_as_user'] = $explicit;
            }
            $actor = $this->actAs($perspective, $world, $explicit);
            $employeeId = $this->employeeIdFor($actor);
            [$params, $notes] = $fixtures->resolve($route, $actor, $employeeId, $forcedId);
            $params = Overrides::params($method.' '.$route->uri(), $params) + $params;
            $url = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($params[$m[1]] ?? 1), $route->uri()), '/');

            $record['url'] = $url;
            $record['fixture_notes'] = $notes;
            $record += $this->gateExpectation($route, $perspective, $world);

            $forced = Overrides::payload($method.' '.$route->uri(), $params);
            [$response, $payload, $attempt, $rules] = $this->sendHealed($method, $url, $forced, $builder);
            $record += $this->describe($response, $payload, $attempt, $rules);
        } catch (Throwable $e) {
            $record['status'] = 0;
            $record['harness_error'] = get_class($e).': '.substr($e->getMessage(), 0, 300).' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine();
            $this->dirty = ! $this->persist;
        }

        $record['ms'] = (int) ((microtime(true) - $started) * 1000);

        return $record;
    }

    private function send(string $method, string $url, array $payload): \Illuminate\Testing\TestResponse
    {
        [$data, $files] = $this->splitFiles($payload);

        if ($files !== []) {
            return $this->call($method, $url, $data, [], $files, ['HTTP_ACCEPT' => 'application/json']);
        }

        return $this->json($method, $url, $data);
    }

    private function actAs(string $perspective, AuditWorld $world, ?int $explicitUserId = null): ?int
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        if ($perspective === 'guest') {
            // Unauthenticated, but fixtures still resolve against the tenant so a record id is real.
            $this->dbm->connect($world->tenant);

            return null;
        }
        if ($perspective === 'super_admin') {
            $this->dbm->connectSystem();
            $this->actingAs($world->superAdmin);

            return null;
        }

        $this->dbm->connect($world->tenant);
        $user = $explicitUserId !== null ? \App\Models\User::findOrFail($explicitUserId) : $world->user($perspective);
        $this->actingAs($user)->withSession(['login.tenant_id' => $world->tenant->id]);

        return (int) $user->id;
    }

    private function employeeIdFor(?int $userId): ?int
    {
        if ($userId === null || ! Schema::hasTable('employees')) {
            return null;
        }

        return DB::table('employees')->where('user_id', $userId)->value('id');
    }

    /** What the route's own middleware says about this role (the audit's independent expectation). */
    private function gateExpectation(LaravelRoute $route, string $perspective, AuditWorld $world): array
    {
        $permissions = [];
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                $permissions[] = substr($mw, strlen('permission:'));
            }
        }
        if (in_array($perspective, ['guest', 'super_admin', 'approver', 'requester'], true) || $permissions === []) {
            return ['gate_permissions' => $permissions, 'gate_ok' => $permissions === [] ? null : false];
        }

        $user = $world->user($perspective);
        $missing = array_values(array_filter($permissions, fn ($p) => ! $user->hasPermission($p)));

        return ['gate_permissions' => $permissions, 'gate_ok' => $missing === [], 'gate_missing' => $missing];
    }

    private function lookup(string $table, string $column, int $offset): mixed
    {
        try {
            foreach ([DB::connection(), DB::connection('iso_system')] as $connection) {
                if ($connection->getSchemaBuilder()->hasTable($table)) {
                    $values = $connection->table($table)->orderBy($column)->limit(25)->pluck($column);
                    if ($values->isNotEmpty()) {
                        return $values[$offset % $values->count()];
                    }
                }
            }
        } catch (Throwable) {
            // fall through
        }

        return null;
    }

    private function resetRuntime(): void
    {
        TenantLimits::resetMemo();
        ReportsTo::resetMemo();
        Cache::flush();
    }

    private function hookValidator(): void
    {
        $factory = $this->app['validator'];
        $factory->resolver(function ($translator, $data, $rules, $messages, $attributes) {
            $this->captured[] = $rules;

            return new Validator($translator, $data, $rules, $messages, $attributes);
        });
    }

    private function hookExceptions(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (Throwable $e) {
                $this->lastException = $e;
            });
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function splitFiles(array $payload): array
    {
        $files = [];
        $walk = function (array $data, string $prefix = '') use (&$walk, &$files): array {
            $clean = [];
            foreach ($data as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if ($value instanceof UploadedFile) {
                    \Illuminate\Support\Arr::set($files, $path, $value);
                } elseif (is_array($value)) {
                    $clean[$key] = $walk($value, $path);
                } else {
                    $clean[$key] = $value;
                }
            }

            return $clean;
        };

        return [$walk($payload), $files];
    }

    private function signature(array $payload): string
    {
        return md5(json_encode($this->printable($payload)) ?: '');
    }

    private function printable(array $payload): array
    {
        array_walk_recursive($payload, function (&$v, $k) {
            if ($v instanceof UploadedFile) {
                $v = '[file '.$v->getClientOriginalName().']';
            } elseif (is_string($k) && str_contains(strtolower($k), 'password')) {
                $v = '[secret]';
            }
        });

        return $payload;
    }

    private function write(string $file, array $record): void
    {
        file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
    }
}
