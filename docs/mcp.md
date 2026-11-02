# FlowSync MCP Server Specification

> **Version**: 1.0.0 (v1 TMS)  
> **Target Audience**: AI coding agents (Claude Code, opencode, Cursor, VS Code), developers, and platform operators.  
> **Status**: Specification & Architectural Decisions (Phase 0).

---

## 1. Architectural Overview & Context

FlowSync provides a Model Context Protocol (MCP) server that enables AI coding assistants to interact directly with tenant Task Management System (TMS) data. The MCP server runs locally as a Node.js process spawned by the agent over standard input/output (`stdio`) and acts as an authenticated client to FlowSync's `/api/v1` REST API.

```
AI Coding Agent (Claude Code / Cursor / opencode / VS Code)
   │ (stdio, JSON-RPC 2.0)
   ▼
FlowSync MCP Server (`node mcp/src/index.js`)
   │ (HTTPS, Authorization: Bearer fst_<tenantId>_<token>)
   ▼
FlowSync Tenant Gateway (`/api/v1`)
   │
   ├── `api_token` (Authenticates token, tenant isolation, enforces offboarding rules)
   ├── `tenant_context` (Ensures tenant database is connected)
   ├── `locale` (Forces English diagnostics via `?locale=en`)
   ├── `onboarding_complete` (Ensures tenant setup is completed)
   ├── `ensure_module:api` (Verifies API module enabled on subscription plan)
   ├── `token_can:<ability>` (Checks capability group: `tms.read`, `tms.write`)
   ├── `throttle:api-token` (Per-token 60-600 rpm rate limiting)
   ├── `idempotent` (Guarantees at-most-once execution for writes via `Idempotency-Key`)
   └── Controller / Policy / Service (Identical policies and row-level scoping as SPA)
```

---

## 2. Architecture Decision Records (ADRs)

### ADR 001: Local `stdio` Transport Over Remote HTTP/SSE
- **Context**: MCP supports local `stdio` (child process communication) and remote HTTP/SSE.
- **Decision**: Implement local `stdio` for v1.
- **Rationale**:
  - Developers run coding agents locally against their active workspace and local or remote FlowSync instances.
  - `stdio` eliminates listener port management, TLS configuration, and external ingress security vectors.
  - Remote HTTP/OAuth 2.1 authentication flow is deferred to v2.

### ADR 002: Capability Groups Over 1:1 Permission Slugs
- **Context**: In FlowSync, task operations are scoped by project-level roles (`tasks.create`, `tasks.edit`, `tasks.move`), not global tenant roles. The previous API token model checked only tenant permissions, which locked out project leads and developers who lack global tenant admin roles.
- **Decision**: Group permissions into two high-level capability groups:
  1. `tms.read`: Granted if the user holds tenant `workspaces.view` OR any project membership granting task read scopes (`tasks.view`, `tasks.view_own`, `tasks.view_assigned`, `tasks.view_all`).
  2. `tms.write`: Granted if the user holds any project membership granting `tasks.create`, `tasks.edit`, or `tasks.move`.
- **Rationale**: Capability groups allow developers and leads to generate tokens matching their real operational capabilities while maintaining strict policy enforcement at request time (e.g., `TaskPolicy`, `TaskScope`, and `WorkflowGuard` still enforce permissions on specific projects and tasks).

### ADR 003: Strict Stdout/Stderr Separation
- **Context**: In MCP `stdio` transport, the agent parses `stdout` as JSON-RPC streams. Any unhandled output (such as `console.log`) corrupts message parsing and terminates the agent connection.
- **Decision**: `stdout` is reserved strictly for JSON-RPC framing. All log output, diagnostics, and debugging traces are directed strictly to `stderr`.

### ADR 004: Resolved Technical Decisions (§12.11)
1. **Per-Token Project Allowlist**: Deferred to v1.1. In v1, token capabilities are tenant-wide for the owner's authorized projects.
2. **Comment Mentions**: Comments posted via API trigger standard notifications and emails. The tool description explicitly informs the agent of this side effect.
3. **Write Attribution**: All mutations made via API tokens record `via: "api"` and the `token_id` in activity history to distinguish human from agent actions.
4. **Task Descriptions**: Rendered as Markdown text with a sensible size cap (50 KB). Raw markup is omitted to save context tokens.
5. **Read Side Effects**: Task view tracking (`TaskViews::record()`) is disabled for API token callers so agent read crawling does not mark the human token owner as having viewed the task.
6. **Machine-Readable Error Codes**: All token refusal paths (`TokenCan`, `EnsureModule`, etc.) emit structured machine error codes (e.g. `token_ability_missing`, `token_read_only`, `usage_limit`).

---

## 3. Ability & Permission Model

| Ability | Required Capabilities (Any Of) | HTTP Middleware Gate |
|---|---|---|
| `tms.read` | Tenant `workspaces.view`, OR project-level `tasks.view`, `tasks.view_own`, `tasks.view_assigned`, `tasks.view_all` | `token_can:tms.read` |
| `tms.write` | Project-level `tasks.create`, `tasks.edit`, `tasks.move` | `token_can:tms.write` + `can_write=true` |

A token can never exceed the permissions held by its creator. Write operations also verify `can_write: true` on the token row.

---

## 4. Endpoint Surface (`/api/v1`)

All endpoints sit under `/api/v1` with the shared middleware pipeline:
`api_token`, `tenant_context`, `locale`, `onboarding_complete`, `ensure_module:api`, `throttle:api-token`.

### 4.1 Read Surface (`tms.read`, product `tms`)
- `GET /api/v1/me` — Current user, tenant, token abilities, active modules, rate limits, and monthly quota.
- `GET /api/v1/workspaces` — List workspaces visible to caller.
- `GET /api/v1/workspaces/{workspace}` — Workspace details and project summary.
- `GET /api/v1/projects` — List projects visible to caller.
- `GET /api/v1/projects/{project}` — Project details, workflow statuses, labels, and member list.
- `GET /api/v1/projects/{project}/tasks` — Paginated task list (`view=list` enforced).
- `GET /api/v1/projects/{project}/tasks/key/{key}` — Task detail lookup by project task key (e.g. `QA-42`).
- `GET /api/v1/projects/{project}/tasks/{task}` — Task detail lookup by numeric ID.
- `GET /api/v1/projects/{project}/tasks/{task}/comments` — Paginated task comments.
- `GET /api/v1/projects/{project}/tasks/{task}/work-logs` — Paginated work logs (`ensure_module:time_tracking`).
- `GET /api/v1/projects/{project}/tasks/{task}/transitions` — Permitted workflow target statuses and blocker reasons.
- `GET /api/v1/projects/{project}/tasks/{task}/activities` — Audit history of task changes.
- `GET /api/v1/projects/{project}/sprints` — Sprint list (`ensure_module:sprints`).
- `GET /api/v1/search/tasks` — Full-text task search (`ensure_module:global_search`).

### 4.2 Write Surface (`tms.write`, product `tms`, `idempotent`)
- `POST /api/v1/projects/{project}/tasks` — Create task.
- `PUT /api/v1/projects/{project}/tasks/{task}` — Update task fields.
- `POST /api/v1/projects/{project}/tasks/{task}/move` — Move task status/order. **Returns 202 if approval required.**
- `POST /api/v1/projects/{project}/tasks/{task}/comments` — Add comment.
- `POST /api/v1/projects/{project}/tasks/{task}/work-logs` — Log time spent (`ensure_module:time_tracking`).

---

## 5. MCP Tool Catalog

| Tool Name | Type | Maps To | Purpose |
|---|---|---|---|
| `flowsync_me` | Read | `GET /api/v1/me` | Diagnostic inspection of current token, quota, and tenant modules. |
| `flowsync_list_workspaces` | Read | `GET /api/v1/workspaces` | Lists visible workspaces. |
| `flowsync_get_workspace` | Read | `GET /api/v1/workspaces/{id}` | Inspects workspace details. |
| `flowsync_list_projects` | Read | `GET /api/v1/projects` | Lists all projects accessible by caller. |
| `flowsync_get_project` | Read | `GET /api/v1/projects/{id}` | Details of a project including workflow statuses and labels. |
| `flowsync_list_tasks` | Read | `GET /api/v1/projects/{p}/tasks` | Lists project tasks with filtering and pagination (`view=list`). |
| `flowsync_get_task` | Read | `GET /api/v1/projects/{p}/tasks/{t}` | Full task details by ID or Project Key (`QA-101`). |
| `flowsync_list_comments` | Read | `GET .../tasks/{t}/comments` | Reads comments and replies on a task. |
| `flowsync_list_worklogs` | Read | `GET .../tasks/{t}/work-logs` | Reads time tracking logs. |
| `flowsync_list_sprints` | Read | `GET /api/v1/projects/{p}/sprints` | Lists active and upcoming sprints. |
| `flowsync_search_tasks` | Read | `GET /api/v1/search/tasks` | Global cross-project task search. |
| `flowsync_get_task_transitions` | Read | `GET .../tasks/{t}/transitions` | Checks legal workflow status moves and blocker explanations. |
| `flowsync_list_task_activity` | Read | `GET .../tasks/{t}/activities` | Inspects recent changes and audit log for a task. |
| `flowsync_create_task` | Write | `POST /api/v1/projects/{p}/tasks` | Creates a new task with idempotency. |
| `flowsync_update_task` | Write | `PUT /api/v1/projects/{p}/tasks/{t}` | Modifies task attributes (title, description, priority, assignee). |
| `flowsync_move_task` | Write | `POST .../tasks/{t}/move` | Moves task status. Distinguishes 200 (moved) from 202 (approval requested). |
| `flowsync_add_comment` | Write | `POST .../tasks/{t}/comments` | Appends a comment to task conversation. |
| `flowsync_log_time` | Write | `POST .../tasks/{t}/work-logs` | Logs time spent on task. |

---

## 6. MCP Resources & Prompts

### Resources
- `flowsync://context/architecture` — System tenancy and architectural summary.
- `flowsync://context/conventions` — Repository coding standards and best practices.
- `flowsync://project/{id}` — Project workflow, board columns, and member directory.
- `flowsync://task/{projectId}/{key}` — Live task specification and details.

### Prompts
- `plan_task` — Loads task, project workflow, and architectural rules to formulate an implementation plan.
- `implement_task` — Assembles task acceptance criteria, description, and source context for code execution.
- `review_task` — Gathers task diffs, comments, and work logs for peer review.
- `daily_digest` — Summarizes tasks assigned to the caller that are pending or in progress.

---

## 7. Error Handling & Failure Modes

| HTTP Status | Condition / Code | Client Handling Strategy |
|---|---|---|
| **401** | `Invalid or expired API token.` | Abort immediately. Prompt developer to generate a new token. |
| **403** | `token_read_only` / `token_ability_missing` | Token lacks write capability or required ability group. |
| **403** | `X-Module-Reason` / `X-Product-Denied` | Plan lacks required module (e.g. `time_tracking`, `sprints`). |
| **403** | `X-Onboarding-Redirect` | Tenant onboarding incomplete. |
| **404** | Not Found | Entity does not exist or caller lacks access. Never disclose existence. |
| **202** | `{pending_approval: true, request_id}` | Move requested, **NOT** executed. Await workflow manager approval. |
| **409** | Idempotency conflict / in-flight collision | Request currently being processed. Retry after a short backoff. |
| **422** | Validation / WorkflowGuard rejection | Semantic failure (e.g. missing required field, blocked by subtask). |
| **429** | `code: usage_limit` | Monthly tenant API quota exhausted. **Do not retry.** |
| **429** | Rate limit (`Retry-After: N`) | Per-minute throttle hit. Sleep for $N$ seconds and retry. |
| **503** | `code: maintenance` | Platform maintenance. Exponential backoff. |
| **5xx** | Internal Server Error | Idempotency record deleted on server; retry only after inspecting state. |

---

## 8. Out of Scope for v1
- Remote HTTP transport / OAuth 2.1 authorization servers.
- HRMS tools (employee directory, leave, attendance, payroll).
- Hard delete operations for tasks, projects, or comments.
- Bulk batch operations and full workspace export.
