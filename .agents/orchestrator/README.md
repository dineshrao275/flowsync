# Agentic workflow: Claude → Gemini → OpenCode (via MCP)

An orchestrator drives one task through: **Claude plans → Gemini validates → OpenCode implements →
sandboxed Docker tests → Claude reviews → correction loop (retry limit) → committed on a task branch.**
The shared MCP server (`mcp-server/`, registered in `.mcp.json` and `opencode.json`) gives all three
clients the same tools and state; the orchestrator owns handoffs, retries and completion.

## Commands
    npm --prefix .agents/orchestrator/mcp-server ci        # once
    node .agents/orchestrator/orchestrator.mjs doctor      # check CLIs, Docker, MCP registration
    node .agents/orchestrator/orchestrator.mjs run --task "<goal>" [--id slug] [--retries 3]
    node .agents/orchestrator/orchestrator.mjs status
    node .agents/orchestrator/orchestrator.mjs resume --id <id>       # after an interruption
    node .agents/orchestrator/orchestrator.mjs approve|reject --id <id> [--note ...]
    node .agents/orchestrator/orchestrator.mjs abort|cleanup --id <id>
    node .agents/orchestrator/selftest/run.mjs              # 94 checks, stub agents, no model/Docker

Exit codes: 0 passed, 1 error, 2 failed, 3 awaiting human approval, 4 blocked (file-claim overlap).

## State and outputs (git-ignored, `.agents/workflow/`)
`state.json`, `tasks/<id>.json`, `events.jsonl`, `PROGRESS.md` (auto-generated), `reports/` (review,
test output, task report). Everything persisted is secret-redacted.

## Safeguards
- Each task runs in its own `git worktree` on branch `agent-workflow/<id>`; your tree and branch are never touched.
- Per-task lease + locked atomic state writes; parallel-task cap (`FLOWSYNC_MAX_PARALLEL_TASKS`, default 2); file-claim conflicts block.
- Claude/Gemini run read-only (plan mode); OpenCode has deny rules (no rm/push/docker/curl/composer/npm/artisan/.env).
- Tests run in a hardened Docker container: no network, read-only, caps dropped, `.env` masked, throwaway APP_KEY.
- Forbidden paths (`.env*`, `.git`, orchestrator, `storage/`, keys…) are auto-reverted. Migrations, infrastructure,
  dependency manifests, deletions, destructive SQL, dangerous PHP and >30 files pause at `awaiting_approval`.
- `passed` = a commit on the task branch. The orchestrator never pushes, merges or deploys; agents cannot mark `passed`.

## Limitations
- Gemini headless cannot call MCP tools unless the user enables the global `mcp(*)` allow rule (not enabled); the
  orchestrator gives Gemini its context in the prompt instead.
- Docker checks cover PHPUnit and Pint only (no JS tests/build).
- Uncommitted work in your tree is not visible to task worktrees (they start from committed HEAD).
- You merge and push task branches yourself. Real-agent runs depend on model quality and availability.
