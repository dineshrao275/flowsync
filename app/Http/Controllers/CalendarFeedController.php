<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Integrations\CalendarFeed;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * A personal calendar subscription. The secret URL is the credential (calendar apps cannot sign in), so it is long,
 * stored only as a hash, shown once, revocable, and the public endpoint answers an identical 404 for anything wrong.
 */
class CalendarFeedController extends Controller
{
    public function __construct(
        private readonly CalendarFeed $feed,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_if($request->user()->is_super_admin ?? false, 404);

        return response()->json(['active' => DB::table('calendar_feeds')->where('user_id', $request->user()->id)->exists()]);
    }

    /** Create or rotate: the previous URL stops working immediately. */
    public function rotate(Request $request): JsonResponse
    {
        abort_if($request->user()->is_super_admin ?? false, 404);
        $tenantId = app(TenantContext::class)->currentId();
        ['token' => $token, 'hash' => $hash] = $this->feed->mint();

        DB::table('calendar_feeds')->updateOrInsert(['user_id' => $request->user()->id], ['token_hash' => $hash, 'last_used_at' => null, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['active' => true, 'url' => url("/api/calendar/{$tenantId}/{$token}"), 'message' => __('Calendar link created. Copy it now; it is not shown again.')]);
    }

    public function destroy(Request $request): JsonResponse
    {
        DB::table('calendar_feeds')->where('user_id', $request->user()->id)->delete();

        return response()->json(['active' => false, 'message' => __('Calendar link revoked.')]);
    }

    public function ics(string $tenantId, string $token): Response
    {
        $this->dbm->connectSystem();
        $tenant = ctype_digit($tenantId) ? Tenant::query()->find((int) $tenantId) : null;
        $body = $tenant && $tenant->isServiceable() && preg_match('/^[A-Za-z0-9]{40}$/', $token)
            ? $this->dbm->using($tenant, function () use ($token, $tenant) {
                $row = DB::table('calendar_feeds')->where('token_hash', hash('sha256', $token))->first();
                $user = $row ? User::query()->find($row->user_id) : null;
                if ($user === null) {
                    return null;
                }
                DB::table('calendar_feeds')->where('id', $row->id)->update(['last_used_at' => now()]);

                return $this->feed->build($user, $tenant->id);
            })
            : null;

        abort_if($body === null, 404);

        return response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }
}
