<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Recipient lookups shared by every notification family.
 */
class RecipientResolver
{
    /**
     * One query for a whole recipient set instead of a find() per recipient.
     *
     * @param  iterable<int|string>  $ids
     * @return Collection<int, User>
     */
    public function usersById(iterable $ids): Collection
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Every login holding a permission, for pool-owned notifications (an hr
     * item belongs to whoever holds manage, not to a named person).
     *
     * @return list<int>
     */
    public function usersWith(string $permission): array
    {
        return User::whereHas('roles.permissions', fn ($query) => $query->where('slug', $permission))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolves @mention tokens in text to users in the current tenant database.
     * A bare token (@viewer) matches email local part or name; a full mailbox
     * token (@viewer@flowsync.test) matches the exact email only. Both are
     * case-insensitive, and a full mailbox that belongs to a different tenant
     * resolves to nobody rather than pinging a same-tenant user who happens to
     * share its local part (owner@globex.test must never notify owner@acme.test).
     *
     * @return Collection<int, User>
     */
    public function mentionUsers(string $text): Collection
    {
        preg_match_all('/@([A-Za-z0-9._-]+(?:@[A-Za-z0-9._-]+)?)/', $text, $matches);

        $users = collect();
        foreach ($matches[1] as $token) {
            $token = mb_strtolower($token);

            $query = User::query()->where(function ($query) use ($token) {
                if (str_contains($token, '@')) {
                    $query->whereRaw('LOWER(email) = ?', [$token]);
                } else {
                    $query->whereRaw('LOWER(email) LIKE ?', [$token.'@%'])
                        ->orWhereRaw('LOWER(name) = ?', [$token]);
                }
            });

            $users = $users->concat($query->get());
        }

        return $users->unique('id')->values();
    }

    /**
     * Every login holding a role id, for role-step approvals (an HR step
     * belongs to whoever holds the role, not to a named person).
     *
     * @return list<int>
     */
    public function usersWithRole(int $roleId): array
    {
        return User::whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
