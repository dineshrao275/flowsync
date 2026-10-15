<?php

namespace App\Support;

/**
 * Resolves a scoped permission check against the grants a user actually holds.
 *
 * A scoped slug is a base permission plus a `_own` / `_assigned` / `_all`
 * suffix (`hrms.leave.view_own`), declared in `config/permissions.php` →
 * `scopes`. The three scopes form a chain:
 *
 *     own  ⊂  assigned  ⊂  all
 *
 * so holding a wider scope satisfies a narrower request, and the unsuffixed
 * legacy slug (`hrms.leave.view`) satisfies any of them because it has always
 * meant "every row" — the one documented exception is listed in
 * `config/permissions.php` → `legacy_scope_aliases` (payroll's legacy
 * `view` has always meant "runs + my own payslip", so it answers `_own` only).
 *
 * Two deliberate non-features:
 *
 *  - `.manage` is NOT folded in. It stays an independent, all-or-nothing gate
 *    exactly as it is checked today; a caller that wants rows narrows by
 *    asking for a `_all`/`_own` variant. Mixing the two would silently widen
 *    `hrms.payroll.manage` (salary structures) into "read every payslip".
 *  - a non-scoped slug resolves to itself, so `granted()` degenerates to an
 *    exact match for every permission outside the `scopes` map.
 */
final class PermissionScope
{
    /**
     * Scope suffixes, narrowest first — the array index is the scope rank.
     *
     * @var list<string>
     */
    public const SCOPES = ['own', 'assigned', 'all'];

    /**
     * Every grant that satisfies a check of `$slug`, most specific first.
     *
     * A caller should treat the check as satisfied when the user holds ANY of
     * these; nothing here implies the user holds `$slug` verbatim, which is
     * why `User::granted()` exists rather than a raw `hasPermission()` loop.
     *
     * @return list<string>
     */
    public static function satisfying(string $slug): array
    {
        $parsed = self::parse($slug);

        if ($parsed === null) {
            return [$slug];
        }

        [$base, $verb, $scope] = $parsed;

        $candidates = [];

        // Wider scopes of the same verb satisfy a narrower request.
        foreach (self::SCOPES as $candidateScope) {
            if (self::rank($candidateScope) >= self::rank($scope)) {
                $candidates[] = "{$base}.{$verb}_{$candidateScope}";
            }
        }

        // The legacy unsuffixed slug: normally "all", unless config says the
        // legacy grant was narrower than that.
        $legacy = "{$base}.{$verb}";
        $legacyScope = self::legacyScope($legacy);

        if (self::rank($legacyScope) >= self::rank($scope)) {
            $candidates[] = $legacy;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The scope a legacy unsuffixed slug has always stood for.
     */
    public static function legacyScope(string $legacySlug): string
    {
        $alias = config('permissions.legacy_scope_aliases')[$legacySlug] ?? null;

        if ($alias === null) {
            return 'all';
        }

        return self::parse($alias)[2] ?? 'all';
    }

    /**
     * Splits `hrms.leave.view_own` into [hrms.leave, view, own].
     *
     * Returns null for a slug that carries no scope suffix — including
     * `hrms.documents.view_sensitive`, whose `_sensitive` tail must never be
     * mistaken for a scope.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function parse(string $slug): ?array
    {
        $dot = strrpos($slug, '.');

        if ($dot === false) {
            return null;
        }

        $tail = substr($slug, $dot + 1);
        $underscore = strrpos($tail, '_');

        if ($underscore === false) {
            return null;
        }

        $scope = substr($tail, $underscore + 1);

        if (! in_array($scope, self::SCOPES, true)) {
            return null;
        }

        return [substr($slug, 0, $dot), substr($tail, 0, $underscore), $scope];
    }

    /**
     * Position of a scope in the chain, narrowest first.
     *
     * Unreachable for an unknown value: every scope that reaches here is
     * either a member of SCOPES or was validated by parse(). Returns -1 so a
     * grant can never widen a check if that ever stops holding.
     */
    private static function rank(string $scope): int
    {
        $rank = array_search($scope, self::SCOPES, true);

        return $rank === false ? -1 : $rank;
    }
}
