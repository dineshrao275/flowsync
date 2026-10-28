<?php

declare(strict_types=1);

namespace Tests\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Throwable;

/**
 * Turns `{project}`, `{task}`, ... into ids of real records. Parameters are resolved from the last to the first, so
 * the parents are derived from the child (a task's project, a document's employee) and always belong together; where
 * the model has an owner column the acting user's own record is preferred, which is what "my leave request" means.
 */
final class RouteParams
{
    /** @var array<string, class-string<Model>> */
    private static array $models = [];

    /** @return array{0: array<string, mixed>, 1: list<string>} params and notes about what could not be resolved */
    public function resolve(Route $route, ?int $actorUserId, ?int $actorEmployeeId, ?int $forcedLastId = null): array
    {
        $names = $route->parameterNames();
        if ($names === []) {
            return [[], []];
        }

        $types = $this->declaredTypes($route);
        $chosen = [];   // name => Model
        $params = [];
        $notes = [];

        foreach (array_reverse($names) as $name) {
            $class = $types[$name] ?? $this->guess($name);
            if ($class === \App\Models\Tenant::class && ! str_contains($route->uri(), 'intake')) {
                // Tenant actions (suspend, subscribe, ...) run against the live audit tenant, not a draft the bootstrap made.
                $audit = \App\Models\Tenant::where('slug', AuditWorld::SLUG)->first();
                if ($audit !== null) {
                    $chosen[$name] = $audit;
                    $params[$name] = $audit->getRouteKey();

                    continue;
                }
            }
            $where = $route->wheres[$name] ?? null;
            // Not a bound model: a scalar id, or a value constrained by the route (`expense|leave`, `pm_...`).
            if ($class === null) {
                $params[$name] = $where !== null ? $this->matching($where) : 1;

                continue;
            }

            $model = ($forcedLastId !== null && $name === end($names))
                ? $class::query()->find($forcedLastId)
                : $this->pick($class, $chosen, $actorUserId, $actorEmployeeId);
            if ($model === null) {
                $params[$name] = 999999;
                $notes[] = 'no '.class_basename($class).' row';

                continue;
            }

            $chosen[$name] = $model;
            $params[$name] = $model->getRouteKey();
        }

        return [array_reverse($params, true), $notes];
    }

    /** @return class-string<Model>|null the model bound by the route's last parameter, if any */
    public function lastModelClass(Route $route): ?string
    {
        $names = $route->parameterNames();
        if ($names === []) {
            return null;
        }
        $last = end($names);

        return $this->declaredTypes($route)[$last] ?? $this->guess($last);
    }

    /** A value that satisfies a route constraint such as `pm_[A-Za-z0-9]+` or `expense|leave`. */
    private function matching(string $regex): string
    {
        if (preg_match('/^[a-z_-]+(\|[a-z_-]+)+$/i', $regex)) {
            return explode('|', $regex)[0];
        }
        foreach (['1', 'audit', 'pm_audit1', 'audit-1', 'AUD-1', '1a2b3c', '2026-11-10', 'abc123'] as $candidate) {
            if (@preg_match('#^(?:'.$regex.')$#', $candidate) === 1) {
                return $candidate;
            }
        }
        if (preg_match('/^([a-z_-]+)/i', $regex, $m)) {
            return $m[1].'audit1';
        }

        return '1';
    }

    /** @param array<string, Model> $chosen */
    private function pick(string $class, array $chosen, ?int $userId, ?int $employeeId): ?Model
    {
        try {
            $probe = new $class;
            $table = $probe->getTable();
            $schema = Schema::connection($probe->getConnectionName());
            $query = $class::query();

            // Parent of something already chosen: take exactly the row the child points at.
            $fk = Str::snake(class_basename($class)).'_id';
            foreach ($chosen as $child) {
                if ($child->getAttribute($fk) !== null) {
                    return $class::query()->find($child->getAttribute($fk));
                }
            }

            // Child of something chosen later in the route (rare), or the actor's own row.
            if ($userId !== null && $schema->hasColumn($table, 'user_id')) {
                $own = (clone $query)->where('user_id', $userId)->orderByDesc($probe->getKeyName())->first();
                if ($own !== null) {
                    return $own;
                }
            }
            if ($employeeId !== null && $schema->hasColumn($table, 'employee_id')) {
                $own = (clone $query)->where('employee_id', $employeeId)->orderByDesc($probe->getKeyName())->first();
                if ($own !== null) {
                    return $own;
                }
            }

            // Newest first: rows the bootstrap created through the app are custom, while the oldest are protected starters.
            return $query->orderByDesc($probe->getKeyName())->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, class-string<Model>> */
    private function declaredTypes(Route $route): array
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return [];
        }
        [$controller, $method] = explode('@', $uses);
        try {
            $types = [];
            foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Model::class)) {
                    $types[$parameter->getName()] = $type->getName();
                }
            }

            return $types;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return class-string<Model>|null */
    private function guess(string $name): ?string
    {
        if (self::$models === []) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models'), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = Str::of($file->getPathname())->after(app_path().'/')->beforeLast('.php')->replace('/', '\\');
                $class = 'App\\'.$relative;
                if (class_exists($class) && is_subclass_of($class, Model::class)) {
                    self::$models[class_basename($class)] ??= $class;
                }
            }
        }

        return self::$models[Str::studly($name)] ?? null;
    }
}
