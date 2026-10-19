<?php

namespace App\Services\Jql;

use App\Models\User;
use App\Services\CustomFields\CustomFieldService;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a parsed query into constraints on a `tasks` query.
 *
 * Safety model: the field list below is the ONLY thing a query can reference (every column, relation
 * and operator is chosen by this class, never taken from the text), every value is bound, and
 * `LIKE` patterns go through App\Support\Like. The caller hands in a query that already carries the
 * viewer's row scope (`TaskScope` / `ScopesVisibleTasks`), and everything here is ANDed onto it, so a
 * query can narrow what you see but never widen it.
 */
final class JqlCompiler
{
    /** field => [kind, extra]. Kinds: str, text, rel, person, many, taskref, sprint, date, num, project, cf */
    private const FIELDS = [
        'key' => ['str', 'tasks.key'],
        'title' => ['str', 'tasks.title'],
        'summary' => ['str', 'tasks.title'],
        'text' => ['text', null],
        'status' => ['rel', 'status'],
        'priority' => ['rel', 'priority'],
        'type' => ['rel', 'issueType'],
        'issuetype' => ['rel', 'issueType'],
        'version' => ['rel', 'version'],
        'fixversion' => ['rel', 'version'],
        'assignee' => ['person', 'assignee'],
        'reporter' => ['person', 'reporter'],
        'label' => ['many', 'labels'],
        'labels' => ['many', 'labels'],
        'component' => ['many', 'components'],
        'components' => ['many', 'components'],
        'parent' => ['taskref', 'parent'],
        'epic' => ['taskref', 'epic'],
        'sprint' => ['sprint', 'sprint'],
        'project' => ['project', 'project'],
        'due' => ['date', 'tasks.due_date'],
        'duedate' => ['date', 'tasks.due_date'],
        'start' => ['date', 'tasks.start_date'],
        'created' => ['date', 'tasks.created_at'],
        'updated' => ['date', 'tasks.updated_at'],
        'resolved' => ['date', 'tasks.completed_at'],
        'points' => ['num', 'tasks.story_points'],
        'estimate' => ['num', 'tasks.estimate_minutes'],
    ];

    /** Fields a query may sort by. */
    private const ORDERABLE = [
        'created' => 'tasks.created_at', 'updated' => 'tasks.updated_at', 'due' => 'tasks.due_date',
        'duedate' => 'tasks.due_date', 'title' => 'tasks.title', 'summary' => 'tasks.title',
        'points' => 'tasks.story_points', 'key' => 'tasks.sequence', 'start' => 'tasks.start_date',
    ];

    private const COMPARE = ['=', '!=', '>', '>=', '<', '<='];

    public function __construct(private readonly User $user) {}

    /** @return list<string> the field names a query may use (for help text / autocomplete) */
    public static function fieldNames(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * @param  array{where: ?array<string, mixed>, order: list<array{0: string, 1: string}>}  $parsed
     */
    public function apply(Builder $query, array $parsed): Builder
    {
        if ($parsed['where'] !== null) {
            $query->where(fn (Builder $q) => $this->node($q, $parsed['where']));
        }

        foreach ($parsed['order'] as [$field, $dir]) {
            if (! isset(self::ORDERABLE[$field])) {
                throw new JqlException(__('You cannot sort by ":field".', ['field' => $field]));
            }
            $query->orderBy(self::ORDERABLE[$field], $dir);
        }

        return $query;
    }

    /** @param array<string, mixed> $node */
    private function node(Builder $q, array $node): void
    {
        switch ($node['type']) {
            case 'and':
                foreach ($node['items'] as $item) {
                    $q->where(fn (Builder $w) => $this->node($w, $item));
                }
                break;
            case 'or':
                foreach ($node['items'] as $i => $item) {
                    $q->{$i === 0 ? 'where' : 'orWhere'}(fn (Builder $w) => $this->node($w, $item));
                }
                break;
            case 'not':
                $q->whereNot(fn (Builder $w) => $this->node($w, $node['item']));
                break;
            default:
                $this->condition($q, $node);
        }
    }

    /** @param array<string, mixed> $c */
    private function condition(Builder $q, array $c): void
    {
        $field = $c['field'];
        $op = $c['op'];

        if (str_starts_with($field, 'cf.')) {
            $this->customField($q, substr($field, 3), $op, $c['values']);

            return;
        }

        if (! isset(self::FIELDS[$field])) {
            throw new JqlException(__('Unknown field ":field". Available: :list.', ['field' => $field, 'list' => implode(', ', array_unique(array_keys(self::FIELDS)))]));
        }

        [$kind, $target] = self::FIELDS[$field];

        // Negative operators are the positive one wrapped in NOT — one code path per field.
        $negative = in_array($op, ['!=', '!~', 'not in', 'not empty'], true);
        $positive = ['!=' => '=', '!~' => '~', 'not in' => 'in', 'not empty' => 'empty'][$op] ?? $op;
        $values = $c['values'];

        $build = function (Builder $w) use ($kind, $target, $positive, $values, $field): void {
            match ($kind) {
                'str' => $this->string($w, $target, $positive, $values, $field),
                'text' => $this->text($w, $positive, $values, $field),
                'rel' => $this->relation($w, $target, $positive, $values, $field),
                'person' => $this->person($w, $target, $positive, $values, $field),
                'many' => $this->many($w, $target, $positive, $values, $field),
                'taskref' => $this->taskRef($w, $target, $positive, $values, $field),
                'sprint' => $this->sprint($w, $positive, $values, $field),
                'project' => $this->project($w, $positive, $values, $field),
                'date' => $this->date($w, $target, $positive, $values, $field),
                'num' => $this->number($w, $target, $positive, $values, $field),
            };
        };

        $negative ? $q->whereNot($build) : $q->where($build);
    }

    // -- field kinds -------------------------------------------------------------------------

    private function string(Builder $q, string $col, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', '~', 'in', 'empty']);

        match ($op) {
            'empty' => $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
            '~' => Like::any($q, [$col], $this->text1($values)),
            'in' => $q->whereIn(DB::raw("LOWER($col)"), array_map('mb_strtolower', $this->texts($values))),
            default => $q->whereRaw("LOWER($col) = ?", [mb_strtolower($this->text1($values))]),
        };
    }

    private function text(Builder $q, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['~']);
        Like::any($q, ['tasks.title', 'tasks.description', 'tasks.key'], $this->text1($values));
    }

    private function relation(Builder $q, string $rel, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', 'in', 'empty']);

        if ($op === 'empty') {
            $q->whereDoesntHave($rel);

            return;
        }

        $names = array_map('mb_strtolower', $this->texts($values));
        $q->whereHas($rel, function (Builder $r) use ($names): void {
            $r->where(function (Builder $m) use ($names): void {
                foreach ($names as $n) {
                    $m->orWhereRaw('LOWER(name) = ?', [$n]);
                    if (ctype_digit($n)) {
                        $m->orWhere('id', (int) $n);
                    }
                }
            });
        });
    }

    private function person(Builder $q, string $rel, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', 'in', 'empty']);

        if ($op === 'empty') {
            $q->whereDoesntHave($rel);

            return;
        }

        $q->whereHas($rel, function (Builder $u) use ($values): void {
            $u->where(function (Builder $m) use ($values): void {
                foreach ($values as $v) {
                    if ($v['t'] === 'fn') {
                        if ($v['v'] !== 'currentuser') {
                            throw new JqlException(__('Unknown function ":fn()".', ['fn' => $v['v']]));
                        }
                        $m->orWhere('users.id', $this->user->id);

                        continue;
                    }
                    $needle = mb_strtolower($v['v']);
                    $m->orWhereRaw('LOWER(users.name) = ?', [$needle])->orWhereRaw('LOWER(users.email) = ?', [$needle]);
                }
            });
        });
    }

    private function many(Builder $q, string $rel, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', 'in', 'empty']);

        if ($op === 'empty') {
            $q->whereDoesntHave($rel);

            return;
        }

        $names = array_map('mb_strtolower', $this->texts($values));
        $q->whereHas($rel, fn (Builder $r) => $r->where(function (Builder $m) use ($names): void {
            foreach ($names as $n) {
                $m->orWhereRaw('LOWER(name) = ?', [$n]);
            }
        }));
    }

    private function taskRef(Builder $q, string $rel, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', 'in', 'empty']);

        if ($op === 'empty') {
            $q->whereDoesntHave($rel);

            return;
        }

        $keys = array_map('mb_strtoupper', $this->texts($values));
        $q->whereHas($rel, fn (Builder $r) => $r->whereIn('key', $keys));
    }

    private function sprint(Builder $q, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', 'in', 'empty']);

        if ($op === 'empty') {
            $q->whereNull('tasks.sprint_id');

            return;
        }

        $names = array_map('mb_strtolower', $this->texts($values));
        $q->whereHas('sprint', function (Builder $s) use ($names): void {
            $s->where(function (Builder $m) use ($names): void {
                foreach ($names as $n) {
                    $n === 'active' ? $m->orWhere('status', 'active') : $m->orWhereRaw('LOWER(name) = ?', [$n]);
                }
            });
        });
    }

    private function project(Builder $q, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, ['=', 'in']);

        $keys = array_map('mb_strtoupper', $this->texts($values));
        $q->whereHas('project', fn (Builder $p) => $p->whereIn('key', $keys));
    }

    private function date(Builder $q, string $col, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, [...self::COMPARE, 'empty']);

        if ($op === 'empty') {
            $q->whereNull($col);

            return;
        }

        $date = $this->parseDate($values[0], $field)->toDateString();

        $q->whereDate($col, $op === '=' ? '=' : $op, $date);
    }

    private function number(Builder $q, string $col, string $op, array $values, string $field): void
    {
        $this->requireOps($field, $op, [...self::COMPARE, 'empty']);

        if ($op === 'empty') {
            $q->whereNull($col);

            return;
        }

        $v = $values[0]['v'];
        if (! is_numeric($v)) {
            throw new JqlException(__('":field" needs a number, got ":value".', ['field' => $field, 'value' => $v]));
        }

        $q->where($col, $op, $v + 0);
    }

    /** @param list<array{t: string, v: string}> $values */
    private function customField(Builder $q, string $key, string $op, array $values): void
    {
        $service = app(CustomFieldService::class);

        if (! $service->enabled()) {
            throw new JqlException(__('Custom fields are not available on your plan.'));
        }

        $definition = $service->definitions('task')->firstWhere('key', $key);

        if ($definition === null) {
            throw new JqlException(__('Unknown custom field ":key".', ['key' => $key]));
        }

        $negative = in_array($op, ['!=', '!~', 'not in', 'not empty'], true);
        $value = $values[0]['v'] ?? '';

        $positive = function (Builder $w) use ($service, $op, $value, $key): void {
            $filter = match ($op) {
                '>', '>=' => ['from' => $value],
                '<', '<=' => ['to' => $value],
                default => $value,
            };
            $service->applyFilters($w, 'task', [$key => $filter], 'tasks.id');
        };

        $negative ? $q->whereNot($positive) : $q->where($positive);
    }

    // -- helpers -----------------------------------------------------------------------------

    /** @param list<string> $allowed */
    private function requireOps(string $field, string $op, array $allowed): void
    {
        if (! in_array($op, $allowed, true)) {
            throw new JqlException(__('":field" does not support that operator.', ['field' => $field]));
        }
    }

    /** @param list<array{t: string, v: string}> $values */
    private function text1(array $values): string
    {
        return $this->texts($values)[0];
    }

    /**
     * @param  list<array{t: string, v: string}>  $values
     * @return list<string>
     */
    private function texts(array $values): array
    {
        return array_map(function (array $v): string {
            if ($v['t'] === 'fn') {
                throw new JqlException(__('The function ":fn()" cannot be used here.', ['fn' => $v['v']]));
            }

            return $v['v'];
        }, $values);
    }

    /** @param array{t: string, v: string} $value */
    private function parseDate(array $value, string $field): Carbon
    {
        $v = $value['v'];

        if ($value['t'] === 'fn') {
            return match ($v) {
                'today', 'now' => Carbon::today(),
                default => throw new JqlException(__('Unknown function ":fn()".', ['fn' => $v])),
            };
        }

        if (preg_match('/^([+-])(\d{1,4})([dwmy])$/i', $v, $m) === 1) {
            $n = (int) $m[2] * ($m[1] === '-' ? -1 : 1);

            return match (strtolower($m[3])) {
                'd' => Carbon::today()->addDays($n),
                'w' => Carbon::today()->addWeeks($n),
                'm' => Carbon::today()->addMonthsNoOverflow($n),
                'y' => Carbon::today()->addYears($n),
            };
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            try {
                return Carbon::createFromFormat('!Y-m-d', $v);
            } catch (\Throwable) {
                // fall through
            }
        }

        throw new JqlException(__('":field" needs a date like 2026-03-31, today(), or an offset such as -7d. Got ":value".', ['field' => $field, 'value' => $v]));
    }
}
