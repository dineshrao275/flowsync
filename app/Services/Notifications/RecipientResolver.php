<?php

declare(strict_types=1);

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

    /** Words after an `@` that may still belong to a full name ("@Acme Corp Owner"). */
    private const MAX_NAME_WORDS = 4;

    /**
     * Resolves @mention tokens in text to users in the current tenant database.
     * A bare token (@viewer) matches email local part or name; a full mailbox
     * token (@viewer@flowsync.test) matches the exact email only; and a full
     * display name, which is what the comment editor's autocomplete inserts
     * (`@Acme Corp Owner`), matches that name exactly (the longest run of words
     * wins, so trailing prose is ignored). All are case-insensitive, and a full
     * mailbox that belongs to a different tenant resolves to nobody rather than
     * pinging a same-tenant user who happens to share its local part
     * (owner@globex.test must never notify owner@acme.test).
     *
     * @return Collection<int, User>
     */
    public function mentionUsers(string $text): Collection
    {
        preg_match_all('/@([\p{L}\p{N}._-]+(?:@[\p{L}\p{N}._-]+)?)((?:[ \t]+[\p{L}\p{N}._\'-]+){0,'.(self::MAX_NAME_WORDS - 1).'})/u', $text, $matches, PREG_SET_ORDER);

        $users = collect();
        foreach ($matches as $match) {
            $token = mb_strtolower($match[1]);

            $query = User::query()->where(function ($query) use ($token) {
                if (str_contains($token, '@')) {
                    $query->whereRaw('LOWER(email) = ?', [$token]);
                } else {
                    $query->whereRaw('LOWER(email) LIKE ?', [$token.'@%'])
                        ->orWhereRaw('LOWER(name) = ?', [$token]);
                }
            });
            $found = $query->get();

            // "@Acme Corp Owner": try the longest run of words first, then shorter ones.
            if (! str_contains($token, '@') && trim($match[2] ?? '') !== '') {
                $words = preg_split('/[ \t]+/', trim($match[2]));
                for ($n = count($words); $n >= 1; $n--) {
                    $name = mb_strtolower($match[1].' '.implode(' ', array_slice($words, 0, $n)));
                    $named = User::query()->whereRaw('LOWER(name) = ?', [$name])->get();
                    if ($named->isNotEmpty()) {
                        $found = $found->concat($named);
                        break;
                    }
                }
            }

            $users = $users->concat($found);
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
