# FlowSync MCP Server

Local Model Context Protocol (MCP) server for FlowSync Task Management System (TMS).  
Enables coding agents (Claude Code, opencode, Cursor, VS Code) to read and write workspaces, projects, tasks, comments, and work logs via `/api/v1` over standard input/output (`stdio`).

---

## Prerequisites

- **Node.js**: >= 18.0.0
- **FlowSync Personal API Token**: Created in **Settings → API Tokens** (e.g. `fst_<tenantId>_<random40>`)
- **Subscription**: Tenant plan must include the `api` module (Pro/Enterprise)

---

## Token Creation & Permissions

1. Log in to FlowSync as a user holding project edit or manage permissions (Editor, Manager, Admin, or Custom Role with `api.manage`).
2. Navigate to **Settings → API Tokens**.
3. Click **Generate Token**:
   - Choose the preset: **Coding Agent (Read/Write)** or **Read-Only Agent**.
   - Abilities: `tms.read` for inspection; `tms.write` for creating/updating tasks, logging time, or commenting.
   - Set expiry (the **Agent Preset** fills 30 days; adjust as needed).
4. Copy the token string immediately (`fst_<tenantId>_<random40>`). The secret is only displayed once and stored hashed on the server.

> **Security Warning**:
> Never commit API tokens into git repositories or configuration files (`.mcp.json`, `.cursor/mcp.json`, `.vscode/mcp.json`).
> Always reference an environment variable such as `${FLOWSYNC_TOKEN}`. If you keep a project-level
> config, use a `.local` variant (`.mcp.local.json`, `.cursor/mcp.local.json`) and add it to `.gitignore` —
> a leaked `fst_` token is a working credential until it expires or is revoked.

---

## Quickstart

1. Install dependencies:
   ```bash
   npm --prefix mcp ci
   ```

2. Test the connection manually:
   ```bash
   FLOWSYNC_URL="http://localhost" \
   FLOWSYNC_TOKEN="fst_1_your_token_here" \
   node mcp/src/index.js
   ```

3. Or test with the MCP Inspector:
   ```bash
   FLOWSYNC_URL="http://localhost" \
   FLOWSYNC_TOKEN="fst_1_your_token_here" \
   npm --prefix mcp run inspect
   ```

> **Note**: Whenever you change environment variables or rotate tokens, restart your agent or editor session to reload the server process.

---

## Agent Configuration

Configuration templates are provided in `mcp/examples/`:

- **Claude Code / Desktop**: `mcp/examples/claude.mcp.json`
- **opencode**: `mcp/examples/opencode.json`
- **Cursor**: `mcp/examples/cursor.mcp.json`
- **VS Code**: `mcp/examples/vscode.mcp.json`

Example Claude Code config (`.mcp.json` or `~/.claude.json`):
```json
{
  "mcpServers": {
    "flowsync": {
      "command": "node",
      "args": [
        "/absolute/path/to/flowsync/mcp/src/index.js"
      ],
      "env": {
        "FLOWSYNC_URL": "http://localhost",
        "FLOWSYNC_TOKEN": "${FLOWSYNC_TOKEN}"
      }
    }
  }
}
```

---

## Environment Variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `FLOWSYNC_URL` | **Yes** | — | Base FlowSync URL (e.g. `http://localhost` or `https://app.flowsync.com`). Remote hosts require HTTPS. |
| `FLOWSYNC_TOKEN` | **Yes** | — | Personal API Token starting with `fst_`. |
| `FLOWSYNC_TIMEOUT_MS` | No | `15000` | HTTP request timeout in milliseconds. |
| `FLOWSYNC_READ_ONLY` | No | `false` | If `true` or `1`, write tools are blocked at the MCP layer before making HTTP requests. |
| `FLOWSYNC_DEBUG` | No | `false` | If `true` or `1`, logs detailed diagnostics with request IDs to `stderr`. |
| `FLOWSYNC_MAX_RPM` | No | `60` | Highest requests-per-minute the client spaces calls for. `0` disables client-side pacing (the server still enforces the token limit). |

The client always keeps **one request in flight at a time** and sends `?locale=en`, a
`User-Agent: flowsync-mcp/<version>` marker, and an `Idempotency-Key` on every write.
`flowsync_me` reports the token's monthly quota (`used`/`limit`) so an agent can check its budget before
a large crawl.

---

## Available Tools

### Identity & Inspection
- `flowsync_me`: Inspect token caller identity, tenant, abilities, rate limits, monthly usage quota, and active modules.

### Read Tools
- `flowsync_list_workspaces`: List workspaces accessible to caller.
- `flowsync_get_workspace`: Get workspace details by ID.
- `flowsync_list_projects`: List visible projects.
- `flowsync_get_project`: Get project details including status columns and members.
- `flowsync_list_tasks`: List project tasks with pagination (`page`, `per_page`) and filters. Always uses `view=list`.
- `flowsync_get_task`: Get comprehensive task details by `task_id` or `task_key`.
- `flowsync_get_task_transitions`: Inspect allowed target statuses and blocker reasons before moving a task.
- `flowsync_list_task_activity`: List audit history and activities on a task.
- `flowsync_list_comments`: List comments and threaded replies on a task.
- `flowsync_list_worklogs`: List logged time entries on a task.
- `flowsync_list_sprints`: List active and planned sprints in a project.
- `flowsync_search_tasks`: Global keyword search across tasks.

### Write Tools
- `flowsync_create_task`: Create a new task (auto-attaches UUID `Idempotency-Key`).
- `flowsync_update_task`: Update task attributes.
- `flowsync_move_task`: Move a task across statuses. Handles HTTP 202 workflow approvals transparently.
- `flowsync_add_comment`: Post a comment on a task.
- `flowsync_log_time`: Log time spent with automatic duration/interval calculation.

---

## Resources

- `flowsync://context/architecture`: Summary of multi-tenant architecture and isolation model.
- `flowsync://context/conventions`: Summary of repository coding conventions and styling rules.
- `flowsync://project/{id}`: Live project configuration, statuses, and members.
- `flowsync://task/{projectId}/{key}`: Live task details.

---

## Prompts

- `plan_task`: Gathers task context and repository conventions to prompt the agent for a phased implementation plan.
- `implement_task`: Prepares instructions for executing code changes, tests, and documentation.
- `review_task`: Prepares instructions for verifying acceptance criteria, test coverage, and security boundaries.
- `daily_digest`: Summarizes active tasks, high-priority work, and blockers for the day.

---

## Troubleshooting & Failure Modes

| Error Condition | Cause | Resolution |
|---|---|---|
| `401 Unauthorized` | Invalid, expired, or revoked token, or inactive tenant. | Re-generate a fresh token in Settings → API Tokens. |
| `403 token_read_only` | Attempting to call write tool with read-only token. | Generate a token with `tms.write` ability. |
| `403 module_missing` | Tenant plan lacks the `api` module. | Upgrade tenant plan or activate API module. |
| `422 Unprocessable` | Validation failed or workflow blocker present. | Check error message details or inspect `flowsync_get_task_transitions`. |
| `429 usage_limit` | Monthly tenant API quota exhausted. | Wait until the 1st of next month or increase tenant API call limits. |
| `429 Rate Limited` | Per-minute burst limit exceeded. | Server automatically retries with backoff according to `Retry-After`. |
| `503 Maintenance` | Platform maintenance in progress. | Wait until maintenance concludes. |

---

## Distribution outside this repository

Non-repo users can install the package from a packed tarball instead of pointing at a checkout:

```bash
npm pack ./mcp                 # produces flowsync-mcp-1.0.0.tgz
npm install -g ./flowsync-mcp-1.0.0.tgz
flowsync-mcp                   # stdio server on PATH
```

Then use `"command": "flowsync-mcp"` in the agent config (with `FLOWSYNC_URL` / `FLOWSYNC_TOKEN` in
`env`). In-repo users should keep using the absolute path to `mcp/src/index.js` shown above.

---

## End-to-end verification

Read-only smoke (works against any tenant with the `api` module):

```bash
FLOWSYNC_URL="http://localhost" \
FLOWSYNC_TOKEN="fst_1_..." \
node mcp/scripts/e2e.mjs
```

Full lifecycle (`create → get → transitions → move → comment → log time`). This **creates real data**,
so run it only against a staging/test tenant:

```bash
FLOWSYNC_E2E_WRITE=1 \
FLOWSYNC_E2E_PROJECT_ID=10 \
FLOWSYNC_URL="http://localhost" \
FLOWSYNC_TOKEN="fst_1_..." \
node mcp/scripts/e2e.mjs
```

The MCP test suite (contract, protocol, stdout hygiene, read/write tools, client) runs with:

```bash
npm --prefix mcp ci
npm --prefix mcp test
```

The contract guard also checks every tool's route against `php artisan route:list` when PHP and a
bootable checkout are available. See `docs/mcp-runbook.md` for the pilot matrix, inspector/real-agent
smoke steps, token rotation, and troubleshooting.
