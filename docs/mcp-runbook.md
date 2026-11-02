# FlowSync MCP Server — Operations Runbook (v1)

Companion to `docs/mcp.md` (contract) and `mcp/README.md` (setup). This is the rollout, verification and
support document.

## 1. Pre-flight

- Node >= 18 on the developer machine.
- Tenant plan includes the `api` module; the user holds `api.manage`.
- A personal token (`fst_<tenantId>_<40chars>`) with `tms.read` (and `tms.write` for writes).
- `FLOWSYNC_URL` reachable; remote hosts must be HTTPS.

## 2. Manual verification gates

These are the two gates CI cannot automate.

### 2.1 Inspector smoke

```bash
FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." \
  npm --prefix mcp run inspect
```

Open <https://inspector.modelcontextprotocol.io> if prompted, connect to the spawned process, then:

1. `initialize` completes; server info is `flowsync-app` 1.0.0.
2. `tools/list` shows 18 tools (13 read incl. `flowsync_me`, 5 write).
3. `resources/list` shows the two context resources plus the project/task templates.
4. `prompts/list` shows `plan_task`, `implement_task`, `review_task`, `daily_digest`.
5. Call `flowsync_me` → identity, tenant, abilities, `can_write`, modules/products, `usage.api_calls`.
6. Call `flowsync_list_workspaces` → visible workspaces.
7. (Write token) Call `flowsync_list_projects`, then `flowsync_get_project`, then create a task in a
   staging project.

Expected: every response is JSON-RPC; diagnostics only appear on stderr.

### 2.2 Real-agent smoke (Claude Code and opencode)

Against a **staging** tenant:

1. Copy the relevant `mcp/examples/*.json` into the project (Claude Code `.mcp.json`, opencode
   `opencode.json`, Cursor `.cursor/mcp.json`, VS Code `.vscode/mcp.json`) and set `FLOWSYNC_TOKEN` in
   the environment — never paste the token into the file.
2. Restart the agent so it loads the server.
3. Run this scripted prompt:
   > Read task `<KEY>`, summarise its status and the legal next statuses, then create a subtask titled
   > "MCP smoke", move it to the next status, add a comment and log 15 minutes.
4. Expected tool calls, in order:
   `flowsync_get_task` → `flowsync_get_task_transitions` → `flowsync_create_task` →
   `flowsync_get_task` (confirmation) → `flowsync_move_task` → `flowsync_add_comment` →
   `flowsync_log_time`.
5. A `202` from `flowsync_move_task` must be reported as "requested, not moved" (approval-gated
   project), never as success.
6. Read-task text containing instruction-like prose must be treated as data (no execution).

## 3. End-to-end harness

Read-only:

```bash
FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs
```

Write lifecycle (creates a task, comment and work log; staging/test tenants only):

```bash
FLOWSYNC_E2E_WRITE=1 FLOWSYNC_E2E_PROJECT_ID=10 \
FLOWSYNC_URL="http://localhost" FLOWSYNC_TOKEN="fst_1_..." node mcp/scripts/e2e.mjs
```

The harness checks `initialize`, `ping`, `tools/list`, `me`, workspaces, resources, prompts, then the
write lifecycle.

## 4. Pilot matrix

Run each row with a real agent; all must fail closed with the documented message.

| Scenario | Setup | Expected |
|---|---|---|
| Read-only token | token with `tms.read`, `can_write=false` | read tools work; a write tool is refused at the MCP layer (`FLOWSYNC_READ_ONLY` not required) and by the server (`token_read_only`) |
| Write token | `tms.read` + `tms.write`, `can_write=true` | read → create → move → comment → log time works |
| Revoke mid-session | revoke the token in Settings | next call 401 "create a new token" |
| Expired token | token past `expires_at` | 401 |
| Suspended tenant | suspend the tenant | 401 |
| `api` module removed | plan without `api` | 403 with module reason (`X-Module-Reason`) |
| Maintenance mode | enable platform maintenance | 503 `code: maintenance`; agent backs off |
| Monthly quota exhausted | exhaust `api_calls_per_month` | 429 `code: usage_limit`; no retry (branch on body, not `Retry-After`) |
| Two tenants / two tokens | run agents for two tenants | no data leakage; cross-tenant ids are 404 |
| Onboarding incomplete | draft/incomplete tenant | 403 with `X-Onboarding-Redirect` |
| Approval-gated move | workflow enforcing move approval | 202 `pending_approval`; task unchanged |
| Workflow/blocker refusal | edit that violates `WorkflowGuard` | 422 with the refusal reason |

## 5. Token rotation and revocation

1. Create the replacement token in **Settings → API Tokens** (the Agent Preset uses a 30-day expiry).
2. Update `FLOWSYNC_TOKEN` in the agent's environment.
3. **Restart the agent/editor** — the MCP process captures env at spawn time.
4. Revoke the old token; confirm `last_used_at` stops moving.
5. An offboarded employee's tokens are revoked automatically when their HRMS status moves to an exit
   state; the token path also refuses an offboarding owner (`employee_exited`).

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Agent shows the server as failed | bad `FLOWSYNC_URL`/token, or server crash | run `node mcp/src/index.js` with `FLOWSYNC_DEBUG=1`; read stderr |
| Server starts, every tool 401 | revoked/expired token, suspended tenant, exited owner | mint a new token; check tenant status |
| 403 on reads | missing `tms.read`, module/product off, onboarding incomplete | check `flowsync_me` `modules`/`products`; read the reason header |
| 403 on writes | read-only token or `can_write=false` | use a write token, or unset `FLOWSYNC_READ_ONLY` if it is on |
| 404 on a known task | not visible to the token owner, or wrong tenant | confirm project membership; tokens never disclose existence |
| 429 `usage_limit` | monthly quota exhausted | do not retry; raise the plan limit or wait for the 1st |
| 429 with `Retry-After` | per-minute throttle | the client retries reads after the delay; serialise/pace calls |
| 503 `code: maintenance` | platform maintenance | back off and retry later |
| A write timed out | first call may still commit | retry with the **same** `Idempotency-Key` (the client does this); `409` = still processing |
| Output corrupts the agent | a stray `console.log` in `mcp/src` | `npm --prefix mcp test` (stdout-hygiene test) fails on this |

## 7. Observability

- Every call lands in the tenant `integration_logs` (including 403/422/429); 401s are not logged.
- Calls count against the plan's monthly `api_calls_per_month` (`UsageMeter`); `flowsync_me` reports
  `usage.api_calls.used/limit`.
- Support: ask for the `X-Request-Id` printed in the tool error, then search the app log for it.
- `FLOWSYNC_DEBUG=1` logs redacted request diagnostics to **stderr**; the token is never printed.

## 8. Rollout checklist

- [ ] `npm --prefix mcp test` green (contract, protocol, stdout, tools, client).
- [ ] CI `mcp` job green (`npm --prefix mcp ci && npm --prefix mcp test`).
- [ ] Inspector smoke (§2.1) and real-agent smoke (§2.2) completed on staging.
- [ ] Pilot matrix (§4) completed.
- [ ] `mcp/CHANGELOG.md` updated; announcement sent.
- [ ] Support has this runbook and the token-rotation steps.

### Announcement draft

> **Coding agents can now work in FlowSync.** The FlowSync MCP server (v1.0.0) lets Claude Code,
> opencode, Cursor and VS Code read workspaces, projects and tasks, and create/update/move tasks,
> comment and log time — through the same permissions, workflow rules and limits as the web app.
> Create a personal token under **Settings → API Tokens** (`tms.read` for read-only agents, add
> `tms.write` for writes), copy the config snippet from `mcp/README.md`, and restart the agent.
> Tokens are personal, revocable, expire by default and can never exceed their creator's rights.
> Setup: `docs/mcp.md` · Operations: `docs/mcp-runbook.md`.

## 9. Verification log

| Date | Gate | Result |
|---|---|---|
| 2026-11-03 | `npm --prefix mcp test` | 30/30 pass (contract incl. `php artisan route:list`, protocol, stdout, tools, client) |
| 2026-11-03 | Root `npm run lint` / `npm run build` | 0 errors / build OK |
| 2026-11-03 | `node mcp/scripts/e2e.mjs` (read-only, local `localhost`, acme read token) | pass — `me`, workspaces, resources, prompts |
| pending | Inspector smoke (§2.1) | not run in CI — manual |
| pending | Real-agent smoke, Claude Code + opencode (§2.2) | not run in CI — manual |
| pending | Full pilot matrix (§4) | not run in CI — manual, staging tenant |
