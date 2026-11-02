# FlowSync MCP Server — Changelog

All notable changes to the `flowsync-mcp` package are recorded here.

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
