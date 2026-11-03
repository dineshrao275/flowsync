# FlowSync MCP Server — Changelog

All notable changes to the `flowsync-mcp` package are recorded here.

## 1.0.2 — 2026-11-03

HR data is view-only through the MCP, and the per-user authorization is now pinned by a matrix test.

### Changed

- Writes through a token can no longer change HR data. Server side: the `/api/v1` HRMS routes are behind `hrms_read_only`
  (any non-GET is refused with `code: hrms_read_only`); a task that HR links (performance-goal evidence, an HR task link, an
  HR-owned ask) is read-only (`hrms_linked_task_read_only`) for edit, move, comment and time logging; and time cannot be logged
  through a token while HR derives attendance from work logs (`hrms_attendance_derivation_enabled`).
- The client explains these refusals as final ("do not retry; change it in the app"), and the write-tool descriptions say so.
- Contract test: no tool may be, call or describe HR data, and the set of write tools is pinned to the five task writes.

## 1.0.1 — 2026-11-03

Fixes from the post-implementation review against `.agents/roadmap/mcp-server-plan.md`.

### Fixed

- **Launching through a symlink** (`npx flowsync-mcp`, `node_modules/.bin`, `npm link`) exited silently: the "am I the main
  script" check compared the symlink path with the module URL. It now compares real paths.
- **Markdown vs HTML.** The app stores task descriptions as HTML. `flowsync_get_task` and the task resource now return compact
  Markdown, capped at 20,000 characters in both the text and the structured result (it was 50 KB, text only), and
  `create_task` / `update_task` descriptions are Markdown that the server converts to HTML. The tool descriptions no longer
  claim more Markdown than comments support.
- **A failed tool call is now an `isError` result** (reason in `content`, detail in `structuredContent.error`) instead of a
  JSON-RPC error; arguments are validated against the tool schema before any request, so a missing id is reported as
  such rather than becoming `projects/undefined/...`. An unknown tool name remains a protocol error.
- **Writes that time out** no longer risk a duplicate: the error says the write may have been applied, and repeating the
  identical call reuses its `Idempotency-Key` for 10 minutes.
- `flowsync_log_time` no longer invents a 30-minute duration; it needs `duration_minutes` or both timestamps.
- `flowsync_add_comment` reports capped @mentions; write-tool descriptions state their side effects.
- `flowsync_list_comments` (newest 100) and `flowsync_list_projects` (first 100) are capped and report what was left out;
  tools returning user text carry an untrusted-content note and banner.
- A token whose owner has left the company gets an accurate message (a new token will not help).
- `http://[::1]` is accepted as a loopback URL; the version is read from `package.json` (server info and `User-Agent`).
- `examples/opencode.json` follows opencode's current schema (`mcp.<name>`, `type: local`, `command` array, `{env:VAR}`).
- Contract test now reads the tools' real calls from source, checks them against OpenAPI and `route:list`, and verifies the
  route's `token_can` middleware; in CI a missing `route:list` fails instead of skipping. The stdout-hygiene test no longer
  depends on a running app and asserts the initialize response.

## 1.0.0 — 2026-11-03

Initial v1 release (TMS over local stdio).

### Added

- Local `stdio` MCP server (`@modelcontextprotocol/sdk`) with a bearer-token client for `/api/v1`.
- Read tools: `flowsync_me`, workspaces, projects, tasks (by id or key), transitions, task activity,
  comments, work logs, sprints, and global task search.
- Write tools: `flowsync_create_task`, `flowsync_update_task`, `flowsync_move_task`,
  `flowsync_add_comment`, `flowsync_log_time`.
- Resources: `flowsync://context/architecture`, `flowsync://context/conventions`,
  `flowsync://project/{id}`, `flowsync://task/{projectId}/{key}`.
- Prompts: `plan_task`, `implement_task`, `review_task`, `daily_digest`.
- Client safety: forced `Idempotency-Key` on writes, read-only mode, one-in-flight request pacing,
  `FLOWSYNC_MAX_RPM` spacing, cached `/me` budget, caller `AbortSignal`, retry rules
  (429 rate limit / 5xx reads only; never `usage_limit`), token redaction, stderr-only logging.
- Startup token verification (fatal `401`/`403`, non-fatal transient outage).
- Tests: contract guard (OpenAPI `operationId` + `php artisan route:list`), protocol conformance,
  stdout hygiene, read/write tool suites, client error/retry/pacing tests, and an E2E harness.
- Agent configuration examples for Claude Code, opencode, Cursor, and VS Code.
- `docs/mcp.md` specification and `docs/mcp-runbook.md` operations runbook.

### Notes

- No delete/bulk/attachment/dependency/checklist/user-role/billing/HRMS tools in v1.
- Task/comment text is treated as untrusted data; the server never executes instructions found in it.
