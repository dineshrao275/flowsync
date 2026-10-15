# 09 — Realtime, Jobs & Mail

## Realtime (Reverb + Echo, `BROADCAST_CONNECTION=reverb`)

Composer `laravel/reverb`; npm `laravel-echo` + `pusher-js`. Client bootstrapped in
`resources/js/echo.js` from `VITE_REVERB_*` env (`.env` only; `.env.example` blank
so tests unaffected).

Events (all queued `ShouldBroadcast`):
- `TaskSynced` → `PrivateChannel('project.{id}')`, `broadcastAs('task.synced')`.
- `CommentSynced` → `PrivateChannel('project.{id}')`, `broadcastAs('comment.synced')`.
- `NotificationSent` → `PrivateChannel('user.{id}')`, `broadcastWith` includes
  `actor{id,name}` for client rendering.

Channel auth (`routes/channels.php`): `user.{id}` self-only; `workspace.{id}` /
`project.{id}` membership. Same checks as realtime sync.

Two hard rules:
1. `/broadcasting/auth` registered in `routes/web.php` with `web → switch_tenant →
   auth` — never via `withRouting(channels:)` (bare `web` group resolves the session
   user on the central connection where tenant users don't exist → every auth 403s).
   Comment in `bootstrap/app.php:33-37` explains.
2. Channel/event names: `PrivateChannel`/Echo applies the `private-` prefix —
   never hardcode `private-` twice.

## Queued jobs (`QUEUE_CONNECTION=database`, central `jobs` table)

Only two jobs: `ProvisionTenantJob` (`tries=1`, no auto-retry; repair via
`tenants:provision`; async from `TenantController::store`, **sync `dispatchSync`**
from `RegisterController` so the registrant can log in immediately) and
`AttendanceRollupJob` (carries central tenant id, wraps its own `using()`, `tries=1`).

**Tenant stamping** (`App\Listeners\SwitchesTenantConnectionForQueuedJobs`,
wired in `AppServiceProvider`): `Queue::createPayloadUsing()` stamps `tenant_id`
onto every payload while a tenant context is active; `JobProcessing` connects that
tenant's DB BEFORE unserialization (`SerializesModels` re-queries then — without
this every queued broadcast dies with "relation tasks does not exist").
`JobProcessed`/`JobExceptionOccurred`/`JobFailed` release again — except `SyncJob`
(`QUEUE_CONNECTION=sync`), where the connection belongs to the dispatching request.

Test rules: `QUEUE_CONNECTION=sync` in tests; `Event::fake()` (NOT
`Broadcast::fake()`) intercepts queued broadcasts; channel auth tested by invoking
`Broadcast::driver()->getChannels()` callbacks directly (NullBroadcaster returns
200/empty).

## Mail (gap — roadmap Phase 4/6)

Verified 2026-10-05: **zero `Mail::` usage in `app/`**; only touchpoints are
`Password::sendResetLink` / `Password::reset`. `.env`: `MAIL_MAILER=log`,
`MAIL_HOST=127.0.0.1:2525`, `MAIL_FROM_ADDRESS="hello@example.com"` (placeholder),
`MAIL_FROM_NAME="${APP_NAME}"`. `User::MustVerifyEmail` commented out. No
Mailables, no notification mail, no queued mail. Requirements when wiring:
real SMTP + from-address per env; queue all notification mail (tenant-stamped);
per-user/per-event prefs; never expose secrets to frontend.
