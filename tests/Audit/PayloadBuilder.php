<?php

declare(strict_types=1);

namespace Tests\Audit;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use ReflectionClass;
use Throwable;

/**
 * Builds a request payload that satisfies the validation rules an endpoint actually ran. The rules are captured from
 * the validator factory during a real submission (so inline validate() calls, FormRequests, Rule::in/enum/exists
 * objects and conditional rules are all seen as the app evaluated them), then turned into values; the 422 messages of
 * the next attempt correct whatever the first guess got wrong (taken, too short, invalid choice, ...).
 */
final class PayloadBuilder
{
    /** @var array<string, int> field => how many values have been generated (rotates candidate choices) */
    private array $tries = [];

    private int $seq = 0;

    /** @param Closure(string $table, string $column, int $offset): mixed $existsLookup */
    public function __construct(private readonly Closure $existsLookup) {}

    /**
     * @param  array<string, mixed>  $rules  field => rule string|array (as captured)
     * @param  array<string, mixed>  $payload  current payload
     * @param  array<string, list<string>>  $errors  field => messages of the last 422
     * @return array<string, mixed>
     */
    public function build(array $rules, array $payload, array $errors): array
    {
        $parsed = [];
        foreach ($rules as $field => $rule) {
            $parsed[(string) $field] = $this->tokens($rule);
        }

        // Fields the validator complained about are regenerated; fields never set yet are generated when required.
        $failing = [];
        foreach (array_keys($errors) as $errorKey) {
            $failing[$this->wildcard((string) $errorKey)] = (string) $errorKey;
        }

        // Scalars first so date/confirmation rules can look at the values they depend on.
        $order = array_keys($parsed);
        usort($order, fn ($a, $b) => $this->dependsOnOthers($parsed[$a]) <=> $this->dependsOnOthers($parsed[$b]));

        foreach ($order as $field) {
            $tokens = $parsed[$field];
            $names = array_column($tokens, 0);
            $required = (bool) array_intersect($names, ['required', 'present', 'required_if', 'required_with', 'required_with_all', 'required_without', 'required_without_all', 'required_unless', 'accepted']);
            $isFailing = isset($failing[$field]);

            if (! $required && ! $isFailing) {
                continue;
            }

            $concrete = str_replace('*', '0', $field);
            if (! $isFailing && Arr::has($payload, $concrete)) {
                continue;
            }
            // `items` (array) is built from its `items.*.x` / `items.*` element rules, one element is enough.
            if (in_array('array', $names, true) && $this->hasChildren($parsed, $field)) {
                $element = $this->element($parsed, $field, $payload, $errors);
                if ($element !== null && (! Arr::has($payload, $concrete) || $isFailing || Arr::get($payload, $concrete) === [])) {
                    Arr::set($payload, $concrete, $element);
                } elseif (! Arr::has($payload, $concrete)) {
                    Arr::set($payload, $concrete, []);
                }

                continue;
            }

            $value = $this->make($field, $tokens, $payload, $errors[$failing[$field] ?? $concrete] ?? []);
            Arr::set($payload, $concrete, $value);

            if (in_array('confirmed', $names, true)) {
                Arr::set($payload, $concrete.'_confirmation', $value);
            }
        }

        // Errors with no captured rule (custom messages, nested keys): fix the common ones by message alone.
        foreach ($errors as $key => $messages) {
            if (isset($parsed[$this->wildcard((string) $key)])) {
                continue;
            }
            $current = Arr::get($payload, (string) $key);
            $text = implode(' ', $messages);
            if (is_string($current) && str_contains($text, 'already been taken')) {
                Arr::set($payload, (string) $key, $current.'-'.++$this->seq);
            } elseif (! Arr::has($payload, (string) $key) && str_contains($text, 'required')) {
                Arr::set($payload, (string) $key, $this->byName((string) $key, 'Audit sample value'));
            }
        }

        return $this->orderRanges($payload);
    }

    /** A range whose end is not after its start (start_date/end_date, from/to, ...) becomes a week long. */
    private function orderRanges(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key) || ! preg_match('/^(start|from|begin)(.*)$/', $key, $m)) {
                continue;
            }
            foreach (['end', 'to', 'until', 'finish'] as $endPrefix) {
                $end = $endPrefix.$m[2];
                if ($end !== $key && isset($payload[$end]) && is_string($payload[$key]) && is_string($payload[$end])) {
                    $isTime = (bool) preg_match('/^\d{2}:\d{2}$/', $payload[$key]) && (bool) preg_match('/^\d{2}:\d{2}$/', $payload[$end]);
                    $isDate = (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $payload[$key]) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $payload[$end]);
                    if ($isTime && $payload[$end] <= $payload[$key]) {
                        $payload[$end] = '17:30';
                    } elseif ($isDate) {
                        $a = $this->safe(fn () => CarbonImmutable::parse($payload[$key]));
                        $b = $this->safe(fn () => CarbonImmutable::parse($payload[$end]));
                        if ($a && $b && $b->lte($a)) {
                            $payload[$end] = strlen($payload[$key]) > 10 ? $a->addDays(7)->toIso8601String() : $a->addDays(7)->toDateString();
                        }
                    }
                }
            }
        }

        return $payload;
    }

    /** @return list<array{0: string, 1: list<string>}> */
    private function tokens(mixed $rule): array
    {
        $items = is_array($rule) ? $rule : explode('|', (string) $rule);
        $out = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                if ($item === '') {
                    continue;
                }
                $parts = explode(':', $item, 2);
                $out[] = [strtolower($parts[0]), isset($parts[1]) ? $this->csv($parts[1]) : []];

                continue;
            }

            if ($item instanceof Enum) {
                $out[] = ['in', $this->enumValues($item)];
            } elseif ($item instanceof In) {
                $out[] = ['in', $this->csv(Str::after((string) $item, ':'))];
            } elseif (is_object($item) && ! $item instanceof Closure) {
                $name = strtolower(class_basename($item));
                if (in_array($name, ['exists', 'unique'], true) && method_exists($item, '__toString')) {
                    $out[] = [$name, $this->csv(Str::after((string) $item, ':'))];
                } elseif ($name === 'password') {
                    $out[] = ['password', []];
                } elseif ($name === 'file') {
                    $out[] = ['file', $this->fileTypes($item)];
                } elseif ($name === 'requiredif') {
                    $cond = $this->prop($item, 'condition');
                    if ($cond instanceof Closure ? (bool) $this->safe(fn () => $cond()) : (bool) $cond) {
                        $out[] = ['required', []];
                    }
                } elseif (in_array($name, ['dimensions', 'imagefile'], true)) {
                    $out[] = ['image', []];
                } elseif (method_exists($item, '__toString')) {
                    $s = (string) $item;
                    $parts = explode(':', $s, 2);
                    $out[] = [strtolower($parts[0]), isset($parts[1]) ? $this->csv($parts[1]) : []];
                }
            }
        }

        return $out;
    }

    /** @param list<array{0: string, 1: list<string>}> $tokens */
    private function make(string $field, array $tokens, array $payload, array $messages): mixed
    {
        $names = array_column($tokens, 0);
        $has = fn (string ...$n): bool => (bool) array_intersect($n, $names);
        $arg = function (string $name, int $i = 0) use ($tokens): ?string {
            foreach ($tokens as [$n, $args]) {
                if ($n === $name) {
                    return $args[$i] ?? null;
                }
            }

            return null;
        };
        $leaf = $this->leaf($field);
        $attempt = $this->tries[$field] = ($this->tries[$field] ?? 0) + 1;
        $text = strtolower(implode(' ', $messages));

        if ($has('file', 'image', 'mimes', 'mimetypes')) {
            return $this->file($leaf, $tokens);
        }

        foreach ($tokens as [$n, $args]) {
            if ($n === 'in' && $args !== []) {
                return $args[($attempt - 1) % count($args)];
            }
        }

        if ($has('exists')) {
            foreach ($tokens as [$n, $args]) {
                if ($n === 'exists') {
                    $table = $args[0] ?? '';
                    $table = str_contains($table, '.') ? Str::afterLast($table, '.') : $table;
                    $column = ($args[1] ?? null) ?: 'id';
                    $value = ($this->existsLookup)($table, $column, $attempt - 1);
                    if ($value !== null) {
                        return $has('array') ? [$value] : $value;
                    }
                }
            }
        }

        if ($has('boolean', 'accepted')) {
            return true;
        }

        if ($has('integer', 'numeric', 'digits', 'digits_between', 'decimal')) {
            return $this->number($tokens, $arg, $leaf);
        }

        if ($has('email')) {
            return 'audit.'.++$this->seq.'@example.test';
        }

        if ($has('url', 'active_url')) {
            return 'https://example.test/audit-'.++$this->seq;
        }

        if ($has('uuid')) {
            return (string) Str::uuid();
        }

        if ($has('timezone')) {
            return 'UTC';
        }

        if ($has('ip', 'ipv4')) {
            return '203.0.113.9';
        }

        if ($has('json')) {
            return '{}';
        }

        if ($has('password') || str_contains($leaf, 'password')) {
            return 'Str0ng!Passw0rd#2026';
        }

        if ($has('date', 'date_format', 'after', 'before', 'after_or_equal', 'before_or_equal', 'date_equals')) {
            return $this->date($tokens, $arg, $payload, $leaf, $text);
        }

        if ($has('array')) {
            return str_ends_with($leaf, '_ids') ? [1] : ['audit'];
        }

        if ($has('regex')) {
            $pattern = implode(',', $tokens[array_search('regex', $names, true)][1]);
            foreach (['audit-value', 'audit_value', 'auditvalue', 'AuditValue', 'audit.value', 'AUD001', '12345', '#336699', 'audit-value-1', 'A1'] as $candidate) {
                if (@preg_match($pattern, $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        $string = $this->byName($leaf, 'Audit sample value '.++$this->seq);

        if (str_contains($text, 'already been taken')) {
            $string .= '-'.++$this->seq;
        }

        $min = (int) ($arg('min') ?? $arg('size') ?? 0);
        $max = (int) ($arg('max') ?? 0);
        if ($min > 0 && strlen($string) < $min) {
            $string = str_pad($string, $min, ' audit');
        }
        if ($max > 0 && strlen($string) > $max) {
            $string = substr($string, 0, $max);
        }

        return $string;
    }

    private function number(array $tokens, Closure $arg, string $leaf): int|float
    {
        $between = null;
        foreach ($tokens as [$n, $args]) {
            if ($n === 'between' && count($args) === 2) {
                $between = [(float) $args[0], (float) $args[1]];
            }
        }
        $min = $between[0] ?? (float) ($arg('min') ?? $arg('gte') ?? 0);
        $max = $between[1] ?? (float) ($arg('max') ?? 1000000);
        $gt = $arg('gt');
        if ($gt !== null) {
            $min = max($min, (float) $gt + 1);
        }
        $value = max($min, min($max, 5.0));
        $decimal = array_intersect(array_column($tokens, 0), ['numeric']) && (str_contains($leaf, 'percent') || str_contains($leaf, 'rate') || str_contains($leaf, 'amount') || str_contains($leaf, 'price'));

        return $decimal ? round($value, 2) : (int) $value;
    }

    private function date(array $tokens, Closure $arg, array $payload, string $leaf, string $hint = ''): string
    {
        $base = CarbonImmutable::parse('2026-10-26'); // a Monday in the recent past: no future-only or blackout clashes
        // Error-driven: a refusal that mentions the past asks for a future date; one that mentions the future, a past date.
        if (str_contains($hint, 'past') || str_contains($hint, 'today or') || str_contains($hint, 'later day') || str_contains($hint, 'in advance')) {
            $base = CarbonImmutable::parse('2026-11-23');
        }
        foreach ($tokens as [$n, $args]) {
            $ref = $args[0] ?? null;
            $refDate = null;
            if ($ref !== null && $ref !== 'today' && $ref !== 'now' && $ref !== 'tomorrow' && $ref !== 'yesterday') {
                $given = Arr::get($payload, $ref);
                $refDate = is_string($given) ? $this->safe(fn () => CarbonImmutable::parse($given)) : null;
            } elseif ($ref !== null) {
                $refDate = CarbonImmutable::parse($ref === 'now' ? 'today' : $ref);
            }
            if (in_array($n, ['after', 'after_or_equal'], true)) {
                $base = ($refDate ?? CarbonImmutable::parse('today'))->addDays($n === 'after' ? 3 : 0 + 2);
            } elseif (in_array($n, ['before', 'before_or_equal'], true)) {
                $base = ($refDate ?? CarbonImmutable::parse('today'))->subDays($n === 'before' ? 3 : 2);
            }
        }
        if (str_contains($leaf, 'birth') || str_contains($leaf, 'dob')) {
            $base = CarbonImmutable::parse('1990-05-15');
        }

        $format = $arg('date_format');
        if ($format !== null) {
            if (str_contains($format, 'H:i') && ! str_contains($format, 'Y')) {
                return '09:30';
            }

            return $base->setTime(9, 30)->format($format);
        }
        if (str_ends_with($leaf, '_at') || str_contains($leaf, 'time')) {
            return $base->setTime(9, 30)->toIso8601String();
        }

        return $base->toDateString();
    }

    private function byName(string $leaf, string $default): string
    {
        $n = strtolower($leaf);

        return match (true) {
            str_contains($n, 'slug') => 'audit-'.++$this->seq,
            $n === 'key' || str_ends_with($n, '_key') => 'audit-key-'.++$this->seq,
            str_contains($n, 'code') => 'AUD'.++$this->seq,
            str_contains($n, 'phone') || str_contains($n, 'mobile') => '+15550100123',
            str_contains($n, 'color') || str_contains($n, 'colour') => '#336699',
            str_contains($n, 'currency') => 'USD',
            $n === 'locale' || $n === 'language' => 'en',
            str_contains($n, 'country') => 'IN',
            str_contains($n, 'zip') || str_contains($n, 'postal') => '560001',
            str_contains($n, 'domain') => 'audit.example.test',
            str_contains($n, 'ifsc') => 'HDFC0001234',
            str_contains($n, 'pan') && strlen($n) <= 4 => 'ABCDE1234F',
            str_contains($n, 'account') => '123456789012',
            default => $default.' for '.$leaf,
        };
    }

    private function file(string $leaf, array $tokens): UploadedFile
    {
        $mimes = [];
        foreach ($tokens as [$n, $args]) {
            if (in_array($n, ['mimes', 'extensions'], true)) {
                $mimes = array_merge($mimes, $args);
            }
            if ($n === 'file' && $args !== []) {
                $mimes = array_merge($mimes, $args);
            }
        }
        $isImage = (bool) array_intersect(array_column($tokens, 0), ['image']) || (bool) array_intersect($mimes, ['png', 'jpg', 'jpeg']) && ! array_intersect($mimes, ['pdf', 'csv']);
        if ($isImage && function_exists('imagecreatetruecolor')) {
            return UploadedFile::fake()->image('audit.png', 64, 64);
        }
        $ext = $mimes[0] ?? (str_contains($leaf, 'csv') || str_contains($leaf, 'import') ? 'csv' : 'pdf');
        $ext = in_array('pdf', $mimes, true) ? 'pdf' : (in_array('csv', $mimes, true) ? 'csv' : $ext);
        if ($ext === 'csv' || $ext === 'txt') {
            return UploadedFile::fake()->createWithContent('audit.'.$ext, "name,email,roles\nAudit Person,audit.person@example.test,viewer\n");
        }
        if ($ext === 'pdf') {
            return UploadedFile::fake()->createWithContent('audit.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        }

        return UploadedFile::fake()->create('audit.'.$ext, 12);
    }

    /** @param list<array{0: string, 1: list<string>}> $tokens */
    private function dependsOnOthers(array $tokens): int
    {
        return (int) (bool) array_intersect(array_column($tokens, 0), ['after', 'before', 'after_or_equal', 'before_or_equal', 'same', 'confirmed']);
    }

    /**
     * One element for an array field: a scalar when the rule is `field.*`, otherwise an object holding every
     * `field.*.child` that is required (or every child when none is, so the element is not empty).
     *
     * @param  array<string, list<array{0: string, 1: list<string>}>>  $parsed
     * @return list<mixed>|null
     */
    private function element(array $parsed, string $field, array $payload, array $errors): ?array
    {
        $count = max(1, (int) ($this->minItems($parsed[$field] ?? [])));

        if (isset($parsed[$field.'.*'])) {
            return array_map(fn () => $this->make($field.'.*', $parsed[$field.'.*'], $payload, []), range(1, $count));
        }

        $children = [];
        foreach ($parsed as $key => $tokens) {
            if (str_starts_with($key, $field.'.*.') && substr_count($key, '.*') === substr_count($field, '.*') + 1) {
                $children[substr($key, strlen($field) + 3)] = $tokens;
            }
        }
        $required = array_filter($children, fn ($t) => (bool) array_intersect(array_column($t, 0), ['required', 'present', 'required_if', 'required_with']));
        $use = $required !== [] ? $required : $children;
        if ($use === []) {
            return null;
        }

        $item = [];
        foreach ($use as $child => $tokens) {
            if (str_contains($child, '.')) {
                continue;
            }
            $item[$child] = $this->make($field.'.*.'.$child, $tokens, $payload, []);
        }

        return array_fill(0, $count, $item);
    }

    /** @param list<array{0: string, 1: list<string>}> $tokens */
    private function minItems(array $tokens): int
    {
        foreach ($tokens as [$n, $args]) {
            if ($n === 'min' && isset($args[0]) && ctype_digit($args[0])) {
                return (int) $args[0];
            }
        }

        return 1;
    }

    /** @param array<string, list<array{0: string, 1: list<string>}>> $parsed */
    private function hasChildren(array $parsed, string $field): bool
    {
        foreach (array_keys($parsed) as $other) {
            if (str_starts_with($other, $field.'.*')) {
                return true;
            }
        }

        return false;
    }

    private function wildcard(string $path): string
    {
        return (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $path);
    }

    private function leaf(string $field): string
    {
        $parts = array_values(array_filter(explode('.', $field), fn ($p) => $p !== '*' && ! ctype_digit($p)));

        return $parts === [] ? $field : end($parts);
    }

    /** @return list<string> */
    private function csv(string $args): array
    {
        return array_map(fn ($v) => (string) $v, str_getcsv($args, ',', '"', ''));
    }

    /** @return list<string> */
    private function enumValues(Enum $rule): array
    {
        $type = $this->prop($rule, 'type');
        if (! is_string($type) || ! enum_exists($type)) {
            return [];
        }

        return array_map(fn ($case) => (string) ($case->value ?? $case->name), $type::cases());
    }

    /** @return list<string> */
    private function fileTypes(object $rule): array
    {
        $ext = $this->prop($rule, 'allowedExtensions');

        return is_array($ext) ? array_values(array_map('strval', $ext)) : [];
    }

    private function prop(object $object, string $name): mixed
    {
        try {
            $p = (new ReflectionClass($object))->getProperty($name);

            return $p->getValue($object);
        } catch (Throwable) {
            return null;
        }
    }

    private function safe(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
