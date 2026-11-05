# Git integration (GitHub App)

How FlowSync connects tenants' GitHub repositories to projects and tasks. The plan and phase history live in
`.agents/roadmap/git-repo-connection-plan.md`; this file is the current-state reference.

## Model

- **One GitHub App, owned by the operator.** Its identity (`GITHUB_APP_*` in the environment) is the platform's;
  it grants no access by itself. A **tenant admin installs the app on their own GitHub organization** and chooses the
  repositories. GitHub then mints short-lived *installation tokens* that can only see those repositories.
- **One installation = one tenant.** The same installation cannot be claimed twice (409). The installer must prove
  they can see the installation (GitHub OAuth `code` -> `/user/installations`), because the `installation_id` in
  the setup redirect is user-editable and sequential.
- **Platform staff never see tenant repository content.** The platform pages and APIs return states, counts and
  timestamps only: no repository names, no payload excerpts, no tokens.

## Data

| Where | Tables | Why there |
|---|---|---|
| System DB | `git_connections` (installation -> tenant, encrypted tokens, `$hidden`), `git_repositories` (catalog, never deleted - `available=false`), `git_webhook_deliveries` (replay guard + health; excerpt only, never bodies) | routing and credentials must work before a tenant DB is open and never ride a tenant export |
| Tenant TMS DB | `project_repositories` (link: connection uuid + provider repo id + options), `task_pull_requests`, `task_commits`, `task_branches`, `git_identities`, `git_event_links` (delivery ledger) | tenant data; included in tenant backups and exports |

Derived rows keep `project_repository_id` `ON DELETE SET NULL`: unlinking keeps history unless the manager asks to
remove it. A disconnect or provider revoke **pauses** links (`sync_enabled=false`), it does not delete them.

## Flows

1. **Install** - `GET api/integrations/git/github/install` (`git.manage`) returns the GitHub URL with an encrypted,
   10-minute, single-use `state`. GitHub redirects to `GET api/integrations/git/github/setup`, which checks state,
   `git.manage`, the plan's `git` module, the installer's OAuth code and installation uniqueness, then stores the
   connection and queues `SyncGitInstallation`.
2. **Webhook** - `POST api/integrations/git/github/webhook`: HMAC over the raw body (bare 404 on any failure), replay guard
   by `X-GitHub-Delivery`, installation id -> tenant, queue `ProcessGitWebhook`, which runs the handler **inside the
   tenant DB** and records `processed|ignored|failed`. No provider API call and no tenant DB on the hot path.
3. **Events** (`Services/Integrations/Git/Webhook/`) - pull requests/reviews, pushes (<= 20 commits), branch create/delete,
   releases, checks/status become task state and **one `task.vcs_event` activity per real change**. Task keys
   (`ABC-123`) match tasks of the link's own project only (unless `allow_cross_project`); bots are skipped; URLs are
   limited to the Git host.
4. **Writes** - only two: *create branch* (`feature/{KEY}-{slug}`, `git.manage` + project settings) and *smart commits*.
   Smart commits are off per link by default; `#comment`, `#time`, `#assign` need `smart_commits_enabled`,
   `#close`/`#transition` also `smart_commit_transitions_enabled`. They act **as the mapped person** (admin-managed
   `git_identities`; no guessing from e-mail), through the same policies, services and workflow rules as the UI, once
   per commit, and every outcome is audited (`git.smart_commit_applied|skipped`, `source=git`).

## Permissions, module, plans

`git.view` (read; editors get it) and `git.manage` (connect, link, branch, identities; admins). Module `git` is on
paid plans; every tenant route also carries `ensure_product:tms`. Platform routes map to `tenants.view` (read) and
`tenants.manage` (sync/disable/enable) in `config/platform_access.php`.

## Secrets and privacy rules

- Private key, webhook secret and OAuth client secret live only in the environment; `php artisan git:check-config`
  reports whether they are set, long enough and (for a key file) mode 0600/0400 - without printing them.
- Tokens are encrypted at rest (`encrypted` cast), `$hidden`, cached encrypted until 120 s before expiry, and never
  appear in any response or log. Provider error bodies are never surfaced (status + `Retry-After` only).
- `LogHttpRequests` never logs the input of `api/integrations/*` or `api/webhooks/*` (PR text, commit messages, OAuth code).
- Payload text is never stored: a delivery keeps byte count and a one-line `event action repo by sender` excerpt, pruned
  after `git.retention_days` (with the tenant-side `git_event_links` ledger) by `git:prune-deliveries`.

## Operations

| Command | Purpose |
|---|---|
| `git:check-config` | audit the GitHub App configuration |
| `git:sync-installations --all|--tenant=ID` | re-sync repository catalogs (daily 02:30; webhooks keep them current) |
| `git:prune-deliveries` | retention (daily 03:35) |
| `git:legacy-status` | tenants still on the legacy per-tenant webhook |

Health: `GET api/platform/health` (`services.git`, informational), `flowsync_git_*` gauges on `/api/metrics`, log lines
`git.webhook.received|processed|failed`, critical `git.webhook.repeated_failures` (5 failures/hour/connection).

## Legacy webhook

`POST api/integrations/github/{tenantId}` (per-tenant secret, `GithubIntegration`) keeps working unchanged. The Security
card shows a deprecation notice when the tenant's plan has the `git` module. No removal is scheduled.
