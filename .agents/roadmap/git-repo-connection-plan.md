# GitHub App — Full Repository Connection Plan

> Status: approved decisions locked, ready for execution.
> Scope: FlowSync TMS ↔ GitHub repositories (installation-based).
> Mode: build — one reviewed commit per task, focused ParaTest per task, full suite at phase gates.
> Owner: Dinesh Rao. Last updated: 2026-11-02.

## 0. Locked decisions

| # | Decision | Value |
|---|---|---|
| 1 | Provider model | **GitHub App only** (installation-based, auto webhooks, per-repo access, installation tokens) |
| 2 | Read scope (v1) | Link + PR / commit / branch / release / checks status on tasks |
| 3 | Write scope (v1) | **Create branch from task** + **smart commits** (`#close`, `#time`, `#comment`) |
| 4 | Out of scope | Two-way GitHub issue sync, GitLab/Bitbucket (interface ready only), diff/CI log viewer, deployment tracking, Jira import |
| 5 | Linking level | **Per project** (`project_repositories`), project manager (`ProjectPolicy::settings`) links |
| 6 | Admin setup | Operator registers the GitHub App; in-app guide + runbook ship with Phase 9 |
| 7 | Smart commits default | **Off** per project; identity must be mapped explicitly |
| 8 | Tenancy | Connection/credential/delivery data in the central `system` DB; project mapping and task state in the tenant TMS DB |

## 1. Current state (verified at HEAD)

| Area | Today |
|---|---|
| Feature | GitHub **webhook → task activity** only (`task.vcs_event`) |
| Service | `app/Services/Integrations/GithubIntegration.php` |
| Controller | `app/Http/Controllers/GithubIntegrationController.php` |
| Public receiver | `POST api/integrations/github/{tenantId}` — `routes/web.php:81`, signed with `tenants.settings.github.secret` |
| Admin API | `GET/PUT api/github-integration` — `routes/web.php:133-134`, `permission:tenant.manage` |
| Admin UI | `resources/js/components/settings/GithubSettings.jsx` on Admin → Security |
| Tests | `tests/Feature/GithubIntegrationTest.php` (green because tests bypass CSRF) |

### Gaps this plan closes

1. **CSRF gap:** the legacy receiver runs in the `web` group but is missing from
   `validateCsrfTokens(except: …)` in `bootstrap/app.php:130`; a real GitHub POST returns **419**.
   Tests are green only because `VerifyCsrfToken::runningUnitTests()` bypasses the check.
2. **No GitHub App / OAuth / installation:** no app credentials, no installation model, no token minting.
3. **No repo catalog:** tenants cannot list, browse or select repositories.
4. **No project link:** no per-project repository mapping.
5. **No queryable state:** PR state, checks, branches and commits exist only as append-only activity lines.
6. **No write actions:** no branch creation, no smart commits, no identity mapping.
7. **No platform visibility:** no connection health, delivery log or re-sync.

## 2. Target architecture

### 2.1 Provider model

Use a **GitHub App** owned by the FlowSync operator:

- GitHub App installation is per GitHub account (org/user) and grants access to selected repositories only.
- The app mints short-lived **installation access tokens** (1 hour) on demand; tokens are cached encrypted,
  never persisted as plaintext.
- App-level webhooks arrive at one URL; the payload's `installation.id` resolves the tenant.
- One installation maps to exactly one tenant. A second tenant attempting to link the same installation is refused.
- A tenant may have **multiple installations** (multiple GitHub orgs), each stored as a `git_connections` row.

A `GitProvider` interface keeps a future GitLab/Bitbucket implementation possible without touching the domain layer.

### 2.2 Data split (respects one-DB-per-tenant)

**Central `system` DB** (`database/migrations/system`):

| Table | Purpose |
|---|---|
| `git_connections` | One row per installation/OAuth connection: `uuid`, `tenant_id`, `provider`, `installation_id`, `account_login`, `account_type`, `auth_type`, encrypted `access_token` / `refresh_token`, `token_expires_at`, `scopes`, `settings`, `status` (`active|suspended|revoked`), `suspended_at`, `revoked_at`, `connected_by`, `connected_by_name`, `last_synced_at`, timestamps |
| `git_repositories` | Catalog per connection: `uuid`, `git_connection_id`, `provider`, `provider_repo_id`, `full_name`, `name`, `owner`, `default_branch`, `private`, `archived`, `disabled`, `available`, `html_url`, `clone_url`, `ssh_url`, `permissions`, `pushed_at`, `provider_updated_at`, timestamps |
| `git_webhook_deliveries` | `delivery_uuid` (unique replay guard), `git_connection_id` nullable, `tenant_id` nullable, `event`, `action`, `status` (`received|processed|ignored|failed`), `error`, `payload_bytes`, `payload_excerpt`, `received_at`, `processed_at` |

Why central: connection credentials and installation routing are platform-level, must work before a tenant DB is
open, and must never leak into a tenant export. Repository catalog mirrors what the installation grants.
Project ↔ repo mapping stays in the tenant DB.

**Tenant TMS DB** (`database/migrations/tenant_tms`):

| Table | Purpose |
|---|---|
| `project_repositories` | `project_id`, `git_connection_uuid`, `provider`, `provider_repo_id`, `full_name`, `default_branch`, `html_url`, `sync_enabled`, `smart_commits_enabled`, `linked_by`, `linked_at`, timestamps; unique `(project_id, provider, provider_repo_id)`; index `(git_connection_uuid, provider_repo_id)` for webhook routing |
| `task_pull_requests` | `task_id`, `project_repository_id`, `provider`, `provider_pr_id`, `number`, `title`, `state` (`open|draft|merged|closed`), `draft`, `author_login`, `author_avatar_url`, `html_url`, `head_branch`, `base_branch`, `merged_at`, `checks_state` (`none|pending|success|failure|neutral|unknown`), `last_event_at`, timestamps; unique `(task_id, project_repository_id, number)` |
| `task_commits` | `task_id`, `project_repository_id`, `sha`, `message`, `author_login`, `author_name`, `html_url`, `committed_at`, timestamps; unique `(task_id, project_repository_id, sha)`; index `sha` |
| `task_branches` | `task_id`, `project_repository_id`, `name`, `provider_ref`, `created_by`, timestamps; unique `(project_repository_id, name)` |
| `git_identities` | `user_id`, `provider`, `provider_user_id`, `login`, `avatar_url`, `linked_at`; unique `(provider, provider_user_id)`, unique `(user_id, provider)` |
| `git_event_links` | `delivery_uuid`, `project_repository_id`, `task_id`, `kind`, `provider_object_id`, timestamps; unique `(delivery_uuid, task_id, kind, provider_object_id)`; index `created_at` (pruning) |

Migration rules:
- Use the schema builder; for raw `ALTER … ADD COLUMN … REFERENCES` follow the AGENTS.md sqlite trap
  (no `constrained()` inside `Schema::table()`), grammar-wrap identifiers, drop indexes before columns in `down()`.
- All tables must be correct on **PostgreSQL and sqlite**.
- `project_repositories` lives in the TMS set; a TMS-only tenant gets it, a non-TMS tenant never queries it.

### 2.3 Webhook flow

```
GitHub
  └─ POST /api/integrations/git/github/webhook  (public, CSRF-exempt, throttled, maintenance-open)
       ├─ verify X-Hub-Signature-256 (HMAC-SHA256, constant-time)
       ├─ upsert git_webhook_deliveries by X-GitHub-Delivery (replay guard)
       ├─ resolve git_connections by payload.installation.id
       │    └─ unknown/disabled → 202 ignored (never leak)
       ├─ dispatch ProcessGitWebhook(deliveryId)->afterCommit()
       └─ 202 {status:"accepted"}
```

`ProcessGitWebhook` (queued, `tries=1`):
1. Re-read delivery; if `processed`, return.
2. Resolve central `GitConnection` + `Tenant`.
3. `TenantDatabaseManager::using($tenant, fn () => $processor->handle(...))`.
4. Update delivery status (`processed|ignored|failed`) + error; log with request id.
5. Never let one failing consumer stop another; never throw out of the job without recording.

### 2.4 Key matching

- Regex: `\b([A-Za-z][A-Za-z0-9]{1,9}-\d{1,6})\b`, normalize with `strtoupper()` before lookup.
- Search fields: PR title, PR body (capped 20 KB), PR head branch, commit message (first line).
- Lookup scope: tasks of the linked project only; optional `allow_cross_project` per link (default off).
- Caps: max 20 unique keys per event, max 20 commits per push (matching the legacy integration).
- Skip bots (`sender.type === "Bot"` or commit author login ending `[bot]`); skip unknown keys silently.
- Activity is written only when a row actually changed (new PR, state change, new commit, new branch, new checks state),
  plus `git_event_links` makes a redelivered payload a no-op.

### 2.5 Token and secret handling

- GitHub App private key: `GITHUB_APP_PRIVATE_KEY` (base64) or `GITHUB_APP_PRIVATE_KEY_PATH`; never in the repo,
  never logged, never returned by an API.
- JWT: hand-rolled RS256 (`openssl_sign`) with `iat = now-60`, `exp = now+600`, `iss = app_id`.
- Installation token: `POST /app/installations/{id}/access_tokens`; cache under
  `git:token:{installation_id}` for `expires_at - 120s`, **encrypted with `Crypt`**.
- Webhook secret: `GITHUB_APP_WEBHOOK_SECRET`; verify every request; no unsigned path.
- Legacy per-tenant secret (`tenants.settings.github.secret`) stays for the legacy endpoint until deprecation.

## 3. Permissions, module, plans, routes

### 3.1 Permissions (`config/permissions.php`)

New base slugs (all-or-nothing; not scope domains):

| Slug | Meaning | Default roles |
|---|---|---|
| `git.view` | Read connections, repositories, development panel | `admin`, `editor`, project leads (via project membership) |
| `git.manage` | Connect/disconnect, link/unlink repository, create branch, identity mapping | `admin` |

- `admin` already holds `*`.
- Keep `tenant.manage` for the legacy webhook card; new integration routes use `git.*`.
- Run `php artisan tenants:provision --all` on rollout so every tenant DB receives the new slugs.

### 3.2 Module (`config/subscriptions.php`)

- Add `module_meta` entry: `'git' => ['label' => 'Git & Repositories', 'group' => 'Platform']`.
- `TenantLimits::productOfModule()` defaults non-HRMS modules to TMS — no change needed.
- Add `git` to **Pro** and **Enterprise** plan module lists; **Starter** stays without it.
- Existing tenants: run `php artisan billing:sync-plans` after deploy.
- `EnsureModule` gate: `ensure_module:git` on every new route.
- `/auth/me` already exposes `modules` via `visibleModules()`; SPA `hasModule('git')` works without changes.

### 3.3 Routes

Public (in `routes/web.php` `api` prefix, `web` group):
- `POST api/integrations/git/github/webhook` — throttle `600,1`, CSRF-exempt, maintenance-open.
- `GET api/integrations/git/github/setup` — signed/encrypted state, CSRF-free GET, throttled `30,1`.

Tenant (auth + `switch_tenant` + `tenant` + `tenant_context` + `ensure_subscribed`):
- `GET api/integrations/git/connections` — `git.view`
- `GET api/integrations/git/github/install` — `git.manage` (redirects to GitHub)
- `DELETE api/integrations/git/connections/{uuid}` — `git.manage`
- `POST api/integrations/git/connections/{uuid}/sync` — `git.manage`, throttle `10,1`
- `GET api/integrations/git/repositories` — `git.view` (picker search, tenant-scoped)
- `GET api/integrations/git/identities`, `POST`, `DELETE {id}` — `git.manage`
- `GET api/projects/{project}/repositories` — `git.view` + project view policy
- `POST api/projects/{project}/repositories` — `git.manage` + `ProjectPolicy::settings`
- `PUT api/projects/{project}/repositories/{link}` — same
- `DELETE api/projects/{project}/repositories/{link}` — same
- `GET api/projects/{project}/repositories/{link}/branches|commits|pull-requests` — `git.view`
- `POST api/projects/{project}/repositories/{link}/branches` — `git.manage` + create-branch flag, throttle `20,1`
- `GET api/projects/{project}/tasks/{task}/development` — `git.view`

All tenant routes also carry `ensure_product:tms` and `ensure_module:git`.

Platform (SA, `config/platform_access.php` entries required):
- `GET api/system/git/connections`
- `GET api/system/git/deliveries`
- `POST api/system/git/connections/{uuid}/sync`
- `POST api/system/git/connections/{uuid}/disable`

Audit lists to update in the same commit:
- `tests/Feature/RouteAuthAuditTest.php` (`PUBLIC`)
- `tests/Feature/RouteAuthorizationAuditTest.php`
- `tests/Audit/FormAuditTest.php` (webhook/form exemptions)
- `config/platform_access.php` (SA routes)

## 4. Phase plan

Each phase is one or more reviewed commits on the working branch, pushed immediately. Per task:
focused `php artisan test --parallel <files>`, `./vendor/bin/pint --test`; for JS `npm run lint && npm test && npm run build`.
Full suite at phase gates marked “cross-cutting”.

---

### Phase 0 — Safety fixes, config, decisions

**Goal:** unblock real webhooks and make the feature switchable.

1. `bootstrap/app.php:130` — add `'api/integrations/github/*'` and `'api/integrations/git/*'` to
   `validateCsrfTokens(except: …)`.
2. `app/Http/Middleware/PlatformMaintenance.php` — add `'api/integrations/github/*'`, `'api/integrations/git/*'`
   to `OPEN`.
3. Create `config/git.php` (disabled by default):
   - `enabled`, `providers.github.app_id`, `slug`, `private_key` / `private_key_path`, `webhook_secret`,
     `api_url` (Enterprise), `token_cache_ttl`, `http_timeout`, `per_page`, `max_keys_per_event`,
     `max_commits_per_push`, `allow_cross_project`, `smart_commits` defaults, `retention_days`.
4. `.env.example` — document `GIT_ENABLED`, `GITHUB_APP_ID`, `GITHUB_APP_SLUG`,
   `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_PRIVATE_KEY_PATH`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_API_URL`.
5. Tests: CSRF-exempt assertion (URI in the except list), maintenance-open assertion, legacy
   `GithubIntegrationTest` still green.
6. Commit this plan file.

**Gate:** focused ParaTest + Pint.

> **Deviation (2026-11-03):** the CSRF and maintenance exceptions are scoped to the **inbound webhook**
> paths only (`api/integrations/github/*`, `api/integrations/git/github/webhook`). The broad
> `api/integrations/git/*` pattern in task 1 would also exempt the tenant session routes
> (`.../git/connections`, …) from CSRF; those, and the setup callback, stay protected and are refused
> during maintenance like every other tenant action.

---

### Phase 1 — GitHub App connection lifecycle

> **Status: done (2026-11-03).** Deviations from the text below, all deliberate:
> - **Installer proof (security).** The setup redirect carries a forgeable `installation_id`; without a check a tenant admin could claim another organisation's installation by guessing the (sequential) id. The app must have *Request user authorization (OAuth) during installation* on, and `GitHubAppClient::userCanAccessInstallation` exchanges the returned `code` and checks `/user/installations`. Fails closed when `GITHUB_APP_CLIENT_ID`/`GITHUB_APP_CLIENT_SECRET` are unset. Added to §5 registration steps.
> - `GET integrations/git/github/install` returns `{url}` JSON (the SPA navigates) instead of a 302.
> - `GitHubAppClient` implements `GitProvider` directly (no separate `GitHubAppProvider`); `createRef`/`listCommits`/`listPullRequests`/`checksFor` land with the phases that use them.
> - Permissions `git.view`/`git.manage`, module `git` and plan wiring (suite Pro/Business/Enterprise, TMS Professional/Enterprise) were pulled forward from Phase 4: the routes need them. `editor` holds `git.view`.
> - `SyncGitInstallation` + `GitRepositorySync` ship now (basic upsert, unavailable-not-deleted); Phase 3 adds scheduler, caches, picker.
> - Disconnect revokes the row and wipes tokens; unlinking project links arrives with `project_repositories` (Phase 4).

**Goal:** a tenant admin installs the app and gets a connection.

1. Central migrations (next numbers, suggested):
   - `2026_12_12_000043_create_git_connections_table.php`
   - `2026_12_12_000044_create_git_repositories_table.php`
   - `2026_12_12_000045_create_git_webhook_deliveries_table.php`
2. Models: `GitConnection`, `GitRepository`, `GitWebhookDelivery` (central connection trait, UUIDs,
   encrypted casts, `$hidden` for tokens).
3. `App\Services\Integrations\Git\GitHubAppJwt` — RS256 signer with clock skew and tests.
4. `GitHubAppClient` — `getInstallation`, `createInstallationToken`, `listInstallationRepositories`
   (Link-header pagination), `createRef`, `getBranch`, `listCommits`, `listPullRequests`, `checksFor`.
   - `Http::timeout(config)`, `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`.
   - 403/429 + `Retry-After` → backoff and a typed exception; never expose response bodies to the UI.
5. `GitProvider` interface + `GitHubAppProvider` + `FakeGitProvider` (tests).
6. `GitConnectionService`:
   - `installUrl(Tenant, User)` → encrypted state (tenant id, user id, nonce, `exp` 10 min)
     + redirect to `https://github.com/apps/{slug}/installations/new?state=…`.
   - `handleSetup(Request)` → validate state, verify the user still has `git.manage` in that tenant,
     fetch installation with JWT, refuse if `installation_id` already linked to another tenant (409),
     upsert connection, queue `SyncGitInstallation`, audit `git.connected`.
   - `disconnect(Tenant, uuid)` → mark revoked, unlink project links on the tenant side, audit `git.disconnected`.
   - `listForTenant`, `present` (no secrets).
7. Controllers + FormRequests + routes (`GitConnectionController`).
8. SPA `/integrations` page shell: connection card, install button, account/status, repo count,
   disconnect with `ConfirmDialog`; use `useResource`/`Resource`/`ErrorState`.
9. Nav entry (Administration) with `capabilities: ['module:git', 'git.view']`, `shared: true`
   (hidden automatically when the module/product is absent).

**Tests:** `GitConnectionTest` — state valid/expired/tampered, setup happy path, duplicate installation 409,
cross-tenant refusal, disconnect, token cache, JWT signature, secrets never in JSON, 403 by permission/product/module.
**Gate:** focused + route audits.

---

### Phase 2 — Webhook infrastructure

> **Status: done (2026-11-03).** Deviations: the job receives the payload through the queue (bodies > 1 MB are recorded but not queued) because only an excerpt is stored; `git:sync-installations` is Phase 3; the SA health view is Phase 8 (the tenant sees its own last-delivery/failure count now). The full-suite gate is deferred to the end-of-programme run by instruction.

**Goal:** secure, idempotent event intake.

1. `GitWebhookController@receive`:
   - `git.enabled` guard; verify `X-Hub-Signature-256` with `GITHUB_APP_WEBHOOK_SECRET`.
   - Read `X-GitHub-Event`, `X-GitHub-Delivery`, raw body.
   - Store/collapse delivery row by `delivery_uuid` (replay → 202, no reprocess).
   - Resolve connection by `payload.installation.id`; unknown/disabled → `ignored` + 202.
   - `ping` → 200 `{message:"pong"}` (still recorded).
   - Dispatch `ProcessGitWebhook`.
2. `ProcessGitWebhook` job as described in §2.3.
3. `GitWebhookProcessor` skeleton + handlers:
   - `installation` (`created|deleted|suspend|unsuspend|new_permissions_accepted`)
   - `installation_repositories` (`added|removed`) → incremental catalog update
   - `repository` (`renamed|transferred|archived|unarchived|deleted`) → catalog + tenant link refresh
4. Payload privacy: store `payload_bytes` + a redacted 200-char excerpt only (no code, no tokens).
5. `git:prune-deliveries` (daily) + `git:sync-installations` registration placeholder.
6. Admin health widget: last delivery, failure count, “re-sync”.

**Tests:** `GitWebhookTest` — valid signature, bad signature 404/202 (do not leak), replay,
unknown installation, disabled/revoked connection, malformed JSON, ping, disabled feature, cross-tenant isolation.
**Gate:** cross-cutting (`bootstrap/app.php`, routes) → full suite.

---

### Phase 3 — Repository catalog & browse APIs

> **Status: done (2026-11-03).** Deviation: browse endpoints are keyed by repository uuid under `integrations/git/repositories/{uuid}/…` (picker preview); the project-scoped `projects/{p}/repositories/{link}/…` aliases arrive with the link table in Phase 4 and delegate to the same `GitRepositoryBrowser`.

**Goal:** installation repositories are listed and browsable.

1. `SyncGitInstallation` job:
   - paginate `GET /installation/repositories` (100/page, cap pages).
   - upsert by `(git_connection_id, provider_repo_id)`.
   - mark missing rows `available = false` (never delete: project links keep history).
   - update `last_synced_at`; audit `git.synced`.
2. `git:sync-installations --all` + scheduler (daily, staggered) + `--tenant=ID` for one.
3. `GitRepositoryService` + provider reads with short cache:
   - branches 60 s, commits 60 s, PRs 60 s, checks 30 s.
   - pagination limits named constants (`PER_PAGE = 100`); no unbounded arrays.
4. Repo picker API: search by `full_name`, tenant-scoped, excludes `available = false`.
5. Admin UI: connection list, repo count, last sync, sync-now, error banner.

**Tests:** `GitRepositorySyncTest` — idempotent sync, pagination, `available` transitions, revoked repo,
HTTP fake contract, 429/backoff, no cross-tenant leak.
**Gate:** focused + JS.

---

### Phase 4 — Per-project repository linking

> **Status: done (2026-11-03).** Deviations: permissions/module/plans were already added in Phase 1; the `repositories` summary on the project payload is not added (the panel and the Phase 6 Overview read the list endpoint, so project lists pay no extra query); unlink with linked data uses the explicit `remove_linked_data` flag and a second confirm in the UI. Phases 1-3 had left two ratchet tests red (inline `validate()` in controllers, `routes/web.php` size); fixed here with FormRequests and a merged `require` line.

**Goal:** project managers link repositories to a project.

1. tenant_tms migration `..._create_project_repositories_table.php` (SQLite/PG safe).
2. `ProjectRepository` model + `ProjectRepositoryService`:
   - link: repository must belong to a connection of the same tenant; duplicate → 422.
   - unlink: remove link + task VCS rows? Keep task rows (history) but mark `sync_enabled=false`?
     Decision: unlink removes `project_repositories` row and its `task_pull_requests`/`task_commits`/
     `task_branches` rows only when the user confirms “remove linked data” (default off).
   - options: `sync_enabled`, `smart_commits_enabled` (default false), `default_branch`.
3. Policy: `ProjectPolicy::settings` (lead/tenant admin), plus `git.manage`.
4. Permissions + module + plans (§3.1, §3.2).
5. SPA `components/project/RepositoriesPanel.jsx` in Project Settings:
   - linked repos list, link modal with search picker, options, unlink confirm.
6. Project detail payload includes `repositories` summary (gated by `git.view` and module).

**Tests:** `GitRepositoryLinkTest` — link/unlink, duplicate, cross-tenant repo denied, project-settings 403,
module/plan gate, migration outcome, tenant isolation.
**Gate:** cross-cutting (permissions/module/plans) → full suite.

---

### Phase 5 — Event processing & task links v2

> **Status: done (2026-11-03).** Deviations: `git_identities` is unique on `(provider, login)` (identities are admin-mapped by login in Phase 7, `provider_user_id` is optional); `task_branches` gains `deleted_at` (a deleted branch is marked, not removed); derived task tables use `ON DELETE SET NULL` for `project_repository_id` so unlinking keeps history unless `remove_linked_data`; releases/reviews dedupe by provider object id (`claimOnce`) rather than by delivery; `git_event_links` pruning is added with the Phase 9 retention pass.

**Goal:** PR/commit/branch/release/checks become queryable task state.

1. tenant_tms migration `..._create_task_vcs_tables.php`:
   `task_pull_requests`, `task_commits`, `task_branches`, `git_identities`, `git_event_links`.
2. `GitLinker`:
   - resolve linked projects by `(git_connection_uuid, provider_repo_id)`.
   - key parsing (§2.4), task lookup by `key`.
   - upsert PR/commit/branch rows; update `checks_state`.
   - write activity `task.vcs_event` only on change; record `git_event_links`.
   - broadcast `task.synced` (throttled in payload).
3. Handlers:
   - `pull_request`: opened/reopened/synchronize/edited/ready_for_review/converted_to_draft/
     review_requested/closed (+ merged/unmerged), labeled/unlabeled.
   - `push`: first 20 commits + branch create/delete (compare `before`/`after`).
   - `create`/`delete`: branch/tag.
   - `release`: published/edited/deleted.
   - `pull_request_review`: submitted/dismissed.
   - `check_suite` / `check_run` / `status`: update `checks_state` for linked PRs/commits.
4. Repo rename/transfer → update `project_repositories.full_name` + `html_url` in every linked tenant.
5. `task.vcs_event` data contract:
   `provider`, `kind` (`pull_request|commit|branch|release|review|checks`), `state`, `title`, `url`,
   `repo`, `number`, `sha`, `delivery`. URLs restricted to `https://github.com/…` (same as legacy).

**Tests:** `GitEventProcessingTest` — every event, merged/closed, multiple tasks in one PR,
duplicate delivery, bot commit, unknown key, cross-project rule, checks states, renamed repo,
activity written once.
**Gate:** focused + full suite.

---

### Phase 6 — Development UI

**Goal:** developers see repo activity on the task.

1. `GET projects/{project}/tasks/{task}/development` → `GitTaskPresenter`:
   PRs (state/checks/url), commits (latest N), branches, linked repos, `can_create_branch`.
2. `components/tasks/DevelopmentPanel.jsx` in `TaskDetail`:
   - PR badges (`open|draft|merged|closed`), checks dot, commit list, branch list, “Open on GitHub”.
   - empty state: “No linked repository” with project-manager CTA.
3. Board `TaskCard` badge: PR count / worst checks state (module-gated, subtle, 12 px floor).
4. Project Overview: linked repositories + last PR/commit.
5. `ActivityFeed.jsx`: extend `task.vcs_event` kinds + i18n (`en` + `es` + parity test).
6. Use `useResource`/`Resource`/`ErrorState`, `DataTable`, semantic tokens; no raw tables.

**Tests:** Vitest for panels + activity rendering; backend feature tests; `npm run lint && npm test && npm run build`.
**Gate:** JS gate + focused API.

---

### Phase 7 — Write actions & smart commits (in scope)

**Goal:** create branches and apply commit commands safely.

1. **Create branch** (`GitBranchService`):
   - name rule: `feature/{KEY}-{slug}` (slug from title, max 60, `[a-z0-9-]`).
   - requires Contents read/write; `git.manage` + `ProjectPolicy::settings` + link `sync_enabled`.
   - provider `POST /repos/{owner}/{repo}/git/refs` from `default_branch`.
   - conflicts/empty repo/permission errors → 422 `form` with a clear message.
   - store `task_branches`, audit `git.branch_created`, return URL.
2. **Smart commits** (`GitSmartCommitService`), per-project toggle default off:
   - Commands: `#comment <text>` (max 2 KB), `#time <duration>` (work log; module `time_tracking`),
     `#close` / `#transition "<status>"`, `#assign @login`.
   - Identity: commit author login → `git_identities` → tenant user. No mapping → skip + audit
     `git.smart_commit_skipped`; never guess.
   - All task changes go through `TaskService::move`/`update`/comment/work-log paths, so workflow,
     blockers, membership and work-log rules all apply.
   - `#time` dedupe by `(task, sha)`; `#close` only when project opts in; never bypass permission.
   - Audit each applied command with `source=git`, `delivery`, `sha`, actor.
3. Identity mapping UI: Integrations → Identities (admin pins GitHub login ↔ tenant user).
   Optional email auto-match toggle, default **off**.
4. Rate limits and caps per delivery; no command runs on a bot commit.

**Tests:** `GitSmartCommitTest` — no identity, no permission, closed task, workflow 422, work-log dedupe,
branch name validation, provider failures, audit rows; `GitBranchTest`.
**Gate:** focused + full suite (touches `TaskService` consumers).

---

### Phase 8 — Platform/SA management & health

1. `/admin/integrations` (SA): connections across tenants, delivery health, failed deliveries,
   re-sync, disable; `config/platform_access.php` entries.
2. Metrics/log counters: `git.webhook.received|processed|failed`, repeated-failure critical log.
3. Optional `GET /api/platform/health` git section (last delivery, stale connections).
4. SPA `pages/admin/PlatformIntegrations.jsx` with `DataTable`.

**Tests:** `GitPlatformAccessTest` — persona 403/200, SA actions audited, no secret exposure.
**Gate:** full suite.

---

### Phase 9 — Hardening, ops, legacy migration

1. Secret/token hardening: private key permissions, rotation runbook, `$hidden`, no logs,
   no secrets in error responses.
2. Replay/SSRF/rate-limit/retention final pass; payload privacy (no code bodies).
3. Legacy `GithubIntegration`:
   - keep the endpoint and tests working;
   - add a deprecation notice on the Security card pointing to Integrations;
   - `git:legacy-status` report (tenants still using the legacy webhook);
   - no breaking removal in this plan.
4. Docs: `docs/architecture/git-integration.md`, `docs/runbook.md` (registration, rotation, re-sync,
   incident steps), `AGENTS.md` (TMS domain, permissions, modules, commands, envs, test counts), `.env.example`.
5. Backup notes: central connection tables in the system backup; `project_repositories`/task VCS rows in
   tenant backups; restore drill includes a connection + linked repo.

**Tests:** cross-tenant isolation matrix, signature/replay, secret leakage, maintenance mode, restore drill.
**Gate:** full suite + manual security checklist (`docs/coding-standards.md §5`).

---

### Phase 10 — QA, docs, rollout

1. Full test matrix (§7), i18n parity, Pint, ESLint, Vitest, build.
2. Real GitHub App E2E on a test repo via ngrok/cloudflared:
   install → ping → PR open/merge → checks → branch create → smart commit (`#comment`, `#time`, `#close`).
3. Two-tenant isolation: two installations, same repo name, zero leakage.
4. Update this plan’s status + `AGENTS.md` test counts; verification report.
5. Rollout order:
   1. `php artisan migrate --database=system --path=database/migrations/system`
   2. `php artisan tenants:provision --all` (new permission slugs + tenant_tms tables)
   3. `php artisan billing:sync-plans` (module `git` on Pro/Enterprise)
   4. Set env, `php artisan config:clear && php artisan queue:restart`
   5. Operator registers/updates the GitHub App
   6. Pilot tenant install → verify
   7. Full rollout + monitor

---

## 5. GitHub App registration guide (operator / dev)

1. GitHub → **Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Fields:
   - Name: `FlowSync` (or the configured product name).
   - Homepage URL: `APP_URL`.
   - Webhook URL: `https://<host>/api/integrations/git/github/webhook`.
   - Webhook secret: generate 32+ random bytes → `GITHUB_APP_WEBHOOK_SECRET`.
   - Setup URL (post-installation): `https://<host>/api/integrations/git/github/setup`.
   - Tick **Request user authorization (OAuth) during installation**; note the **Client ID** and generate a **Client secret** (`GITHUB_APP_CLIENT_ID`/`GITHUB_APP_CLIENT_SECRET`). Callback URL: the same setup URL.
   - “Redirect on update”: enabled.
3. Repository permissions:
   - Metadata: **Read** (mandatory).
   - Contents: **Read and write** (create branch).
   - Pull requests: **Read**.
   - Commit statuses: **Read**.
   - Checks: **Read**.
   - Issues / Actions / Administration: **No access**.
4. Subscribe to events:
   - Pull request, Pull request review, Push, Create, Delete, Release,
     Check suite, Check run, Status, Repository, Installation, Installation repositories.
5. Create the app → note **App ID** and **slug**.
6. Generate a **private key** (`.pem`); store it outside the repository (or base64 into env).
7. Install App → choose org/account → select repositories (all or specific).
8. Env:
   ```
   GIT_ENABLED=true
   GITHUB_APP_ID=...
   GITHUB_APP_SLUG=...
   GITHUB_APP_PRIVATE_KEY=base64:...        # or GITHUB_APP_PRIVATE_KEY_PATH=/run/secrets/github-app.pem
   GITHUB_APP_WEBHOOK_SECRET=...
   # GITHUB_APP_API_URL=https://ghe.example.com/api/v3   # GitHub Enterprise only
   ```
9. Local dev: expose `APP_URL` with ngrok/cloudflared, update the app webhook/setup URLs to the tunnel,
   `php artisan config:clear && php artisan queue:restart`.
10. Verify: GitHub “Recent Deliveries” shows the `ping` 200; Integrations page shows the connection and repo count.

## 6. End-user flow (after implementation)

1. Tenant admin → **Integrations** → **Install GitHub App** → choose org and repositories.
2. Project → **Settings → Repositories** → **Link repository** → choose default branch and options
   (sync on, smart commits off by default).
3. A PR/commit/branch that mentions `FLOW-123` → task **Development** section and activity feed show it.
4. Project manager → **Create branch** (optional) → `feature/FLOW-123-…` appears on GitHub.
5. Admin maps GitHub logins → tenant users; then commit messages like
   `FLOW-123 #comment ready for QA #time 2h` update the task through the normal rules, fully audited.
6. Admin can rotate the secret, re-sync repositories, or disconnect; the legacy webhook stays available
   with a deprecation notice.

## 7. Test matrix (definition of done)

| Area | Cases |
|---|---|
| Connection | install state valid/expired/tampered, setup, duplicate installation, cross-tenant refusal, disconnect, token cache, JWT signature, secret never in JSON |
| Webhook | valid/invalid signature, replay, unknown installation, disabled/revoked connection, malformed JSON, ping, maintenance mode, delivery prune |
| Events | PR all actions, merged/closed, push commits, branch create/delete, release, review, checks/status, multiple tasks, bot, unknown key, cross-project rule, renamed repo |
| Isolation | two tenants + two installations + same repo name; zero cross-tenant rows/activities |
| Authorization | `git.view`/`git.manage`, `ProjectPolicy::settings`, `ensure_module:git`, `ensure_product:tms`, SA persona routes |
| Smart commits | no identity, no permission, closed task, workflow rule 422, work-log dedupe, bot skip, audit |
| Branch create | name validation, default branch, empty repo, provider failure, permission refusal |
| Migrations | outcome asserted (tables/columns/indexes), SQLite + PostgreSQL safety, repair re-run no-op |
| Frontend | Vitest panels, i18n parity `en`/`es`, a11y, ESLint 0 warnings, build |
| Ops | backup/restore with a connection, retention prune, secret rotation, health counters |

Gates:
- per task: focused `php artisan test --parallel <files>` + `./vendor/bin/pint --test`
- JS: `npm run lint && npm test && npm run build`
- phase gates marked cross-cutting: full `php artisan test --parallel`
- final: full suite + manual E2E on a real GitHub App

## 8. Risks and mitigations

| Risk | Mitigation |
|---|---|
| GitHub API rate limits | cache reads, queue writes, backoff on 403/429, never call the API on the webhook hot path |
| Cross-tenant leakage | installation → tenant mapping only; repo ownership check on link; isolation tests |
| CSRF 419 on real webhooks | Phase 0 exception + explicit assertion |
| Token/secret leakage | encrypted cache, `$hidden`, no logs/errors, rotation runbook |
| Plan/module missing on existing tenants | `tenants:provision --all` + `billing:sync-plans` in rollout |
| Smart commit abuse | off by default, identity required, all actions via `TaskService`, audit every command |
| 300-line class ceiling | split provider/processor/linker by responsibility from day one |
| Legacy integration breakage | keep endpoint + tests until an explicit deprecation phase |
| SQLite vs PostgreSQL drift | builder or grammar-wrapped raw SQL; outcome tests; PG provisioning check |

## 9. Explicitly out of scope (future)

- GitLab / Bitbucket providers (interface only).
- Two-way GitHub issue sync.
- Diff viewer, CI log viewer, deployment tracking.
- Jira import.
- OAuth user-authorization flow (identity mapping starts as admin-managed).

## 10. Assumptions

- Operator can register a GitHub App and set envs.
- A public HTTPS URL exists in production; dev uses a tunnel.
- The tenant has an active/trial subscription (webhook refuses suspended/expired tenants, like the legacy path).
- Queue workers run (webhook processing is queued).
- `git` module is on the tenant’s plan or granted via a super-admin override.
