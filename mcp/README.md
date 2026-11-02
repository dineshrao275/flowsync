# FlowSync MCP Server

Local Model Context Protocol (MCP) server for FlowSync Task Management System (TMS).  
Enables coding agents (Claude Code, opencode, Cursor, VS Code) to read and write workspaces, projects, tasks, comments, and work logs via `/api/v1`.

---

## Prerequisites

- **Node.js**: >= 18.0.0
- **FlowSync Personal API Token**: Created in **Settings → API Tokens** (e.g. `fst_<tenantId>_<random40>`)
- **Subscription**: Tenant plan must include the `api` module (Pro/Enterprise)

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

---

## Agent Configuration

See configuration templates in `mcp/examples/`:
- `claude.mcp.json` (Claude Code / `~/.claude.json`)
- `opencode.json` (opencode)
- `cursor.mcp.json` (Cursor)
- `vscode.mcp.json` (VS Code)

> **Security Note**: Never commit API tokens directly into your project's configuration files. Always reference an environment variable such as `${FLOWSYNC_TOKEN}`.

---

## Environment Variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `FLOWSYNC_URL` | **Yes** | — | Base FlowSync URL (e.g. `http://localhost` or `https://app.flowsync.com`). |
| `FLOWSYNC_TOKEN` | **Yes** | — | Personal API Token starting with `fst_`. |
| `FLOWSYNC_TIMEOUT_MS` | No | `15000` | HTTP request timeout in milliseconds. |
| `FLOWSYNC_READ_ONLY` | No | `false` | If `true` or `1`, write tools are blocked at the MCP layer. |
| `FLOWSYNC_DEBUG` | No | `false` | If `true` or `1`, logs detailed diagnostics with request IDs to `stderr`. |
