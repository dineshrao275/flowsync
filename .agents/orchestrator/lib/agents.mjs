import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { MCP_SERVER_ENTRY, PROJECT_ROOT } from './paths.mjs';
import { redact } from './redact.mjs';

const MAX_CAPTURE = 8 * 1024 * 1024;

/**
 * Agents inherit NOTHING from the orchestrator's environment except what they need to run and
 * authenticate. In particular no Stripe/database/app secrets from a developer shell reach a model.
 */
const BASE_ENV = ['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'SHELL', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR', 'NODE_OPTIONS'];
const AUTH_ENV = {
  claude: ['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_BASE_URL', 'CLAUDE_CODE_OAUTH_TOKEN'],
  gemini: ['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_APPLICATION_CREDENTIALS'],
  opencode: ['OPENCODE_API_KEY', 'OPENROUTER_API_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GOOGLE_API_KEY'],
};

export function safeEnv(agent, extra = {}) {
  const env = {};
  for (const k of [...BASE_ENV, ...(AUTH_ENV[agent] || [])]) if (process.env[k] !== undefined) env[k] = process.env[k];
  return { ...env, ...extra };
}

export function runProcess(cmd, args, { cwd, env, timeoutMs, input } = {}) {
  return new Promise((resolve) => {
    const child = spawn(cmd, args, { cwd, env, stdio: [input ? 'pipe' : 'ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    let timedOut = false;
    child.stdout.on('data', (b) => {
      if (stdout.length < MAX_CAPTURE) stdout += b.toString();
    });
    child.stderr.on('data', (b) => {
      if (stderr.length < MAX_CAPTURE) stderr += b.toString();
    });
    if (input) child.stdin.end(input);
    const timer = setTimeout(() => {
      timedOut = true;
      child.kill('SIGTERM');
      setTimeout(() => child.kill('SIGKILL'), 5000).unref();
    }, timeoutMs);
    child.on('close', (code) => {
      clearTimeout(timer);
      resolve({ code: timedOut ? 124 : code ?? 1, stdout, stderr, timedOut });
    });
    child.on('error', (e) => {
      clearTimeout(timer);
      resolve({ code: 127, stdout, stderr: String(e.message), timedOut: false });
    });
  });
}

// ---------------------------------------------------------------- JSON schemas (the contracts)

export const SCHEMAS = {
  plan: {
    type: 'object',
    required: ['title', 'requirements', 'targetFiles', 'tests', 'acceptanceCriteria'],
    properties: {
      title: { type: 'string' },
      requirements: { type: 'string' },
      targetFiles: { type: 'array', items: { type: 'string' } },
      tests: { type: 'array', items: { type: 'string' }, description: "Each entry is 'tests/<path>.php' or 'filter:<PHPUnit --filter expression>'" },
      acceptanceCriteria: { type: 'array', items: { type: 'string' } },
      dependencies: { type: 'array', items: { type: 'string' } },
      riskNotes: { type: 'array', items: { type: 'string' } },
    },
  },
  validation: {
    type: 'object',
    required: ['verdict', 'analysis'],
    properties: {
      verdict: { type: 'string', enum: ['approved', 'gaps_identified', 'rejected'] },
      analysis: { type: 'string' },
      gaps: { type: 'array', items: { type: 'string' } },
      edgeCases: { type: 'array', items: { type: 'string' } },
      recommendations: { type: 'array', items: { type: 'string' } },
    },
  },
  review: {
    type: 'object',
    required: ['verdict', 'summary'],
    properties: {
      verdict: { type: 'string', enum: ['approved', 'changes_requested', 'rejected'] },
      summary: { type: 'string' },
      issuesFound: { type: 'array', items: { type: 'object', required: ['severity', 'description'], properties: { severity: { type: 'string', enum: ['blocker', 'major', 'minor'] }, file: { type: 'string' }, description: { type: 'string' }, fix: { type: 'string' } } } },
      suggestions: { type: 'array', items: { type: 'string' } },
    },
  },
};

export function extractJson(text) {
  if (!text) return null;
  const tryParse = (s) => {
    try {
      return JSON.parse(s);
    } catch {
      return null;
    }
  };
  const direct = tryParse(text.trim());
  if (direct && typeof direct === 'object') return direct;
  const fence = text.match(/```(?:json)?\s*([\s\S]*?)\s*```/);
  if (fence) {
    const v = tryParse(fence[1]);
    if (v) return v;
  }
  const a = text.indexOf('{');
  const b = text.lastIndexOf('}');
  return a !== -1 && b > a ? tryParse(text.slice(a, b + 1)) : null;
}

// ---------------------------------------------------------------- fake agents (self-test only)

async function fakeAgent(role, prompt, cwd) {
  const dir = process.env.FLOWSYNC_FAKE_AGENTS_DIR;
  const script = path.join(dir, `${role}.mjs`);
  const r = await runProcess(process.execPath, [script], { cwd, env: { PATH: process.env.PATH, FLOWSYNC_FAKE_STATE: process.env.FLOWSYNC_FAKE_STATE || '' }, timeoutMs: 60000, input: JSON.stringify({ role, cwd, prompt }) });
  if (r.code !== 0) throw new Error(`fake ${role} failed: ${r.stderr || r.stdout}`);
  return r.stdout;
}

// ---------------------------------------------------------------- Claude Code (planner / reviewer)

const CLAUDE_READ_TOOLS = ['Read', 'Grep', 'Glob', 'Bash(git diff:*)', 'Bash(git log:*)', 'Bash(git show:*)', 'Bash(git status:*)', 'Bash(ls:*)'];
const CLAUDE_MCP_TOOLS = ['get_project_context', 'get_task', 'get_task_diff', 'get_workflow_state', 'get_git_status'].map((t) => `mcp__flowsync-dev__flowsync_${t}`);

/** Claude plans and reviews. It runs in plan mode with read-only tools: it cannot edit, write or execute anything else. */
export async function runClaude({ role, prompt, schema, cwd, timeoutMs = 600000 }) {
  if (process.env.FLOWSYNC_FAKE_AGENTS_DIR) return { data: extractJson(await fakeAgent(role, prompt, cwd)), raw: '' };

  const mcpConfig = path.join(PROJECT_ROOT, '.mcp.json');
  const args = [
    '-p', prompt,
    '--permission-mode', 'plan',
    '--no-session-persistence',
    '--output-format', 'json',
    '--json-schema', JSON.stringify(schema),
    ...(fs.existsSync(mcpConfig) ? ['--mcp-config', mcpConfig, '--strict-mcp-config'] : []),
    '--allowedTools', ...CLAUDE_READ_TOOLS, ...CLAUDE_MCP_TOOLS,
    '--disallowedTools', 'Edit', 'Write', 'NotebookEdit',
  ];
  const r = await runProcess('claude', args, { cwd, env: safeEnv('claude'), timeoutMs });
  if (r.code !== 0 && !r.stdout.trim()) throw new Error(`Claude Code ${role} failed (exit ${r.code}): ${redact(r.stderr).slice(-800)}`);
  const envelope = extractJson(r.stdout);
  if (!envelope) throw new Error(`Claude Code ${role} returned no JSON: ${redact(r.stdout).slice(-400)}`);
  if (envelope.is_error) throw new Error(`Claude Code ${role} reported an error: ${redact(String(envelope.result || '')).slice(0, 600)}`);
  const data = envelope.structured_output || extractJson(String(envelope.result || ''));
  if (!data) throw new Error(`Claude Code ${role} produced no structured output.`);
  return { data, raw: redact(String(envelope.result || '')), costUsd: envelope.total_cost_usd };
}

// ---------------------------------------------------------------- Gemini CLI (independent validator)

/**
 * Gemini validates independently, in read-only plan mode. Headless MCP calls are denied by Gemini
 * unless the user opted in (permissions.allow `mcp(*)` in their settings), so the prompt carries the
 * whole context and tells it not to call tools; an empty answer caused by a denied tool call is retried once.
 */
export async function runGemini({ role = 'validate', prompt, schema, cwd, timeoutMs = 600000 }) {
  if (process.env.FLOWSYNC_FAKE_AGENTS_DIR) return { data: extractJson(await fakeAgent(role, prompt, cwd)), raw: '' };

  const attempt = async (p) => {
    const args = ['-p', p, '--mode', 'plan', '--effort', 'low', '--output-format', 'json', '--json-schema', JSON.stringify(schema)];
    const r = await runProcess('gemini', args, { cwd, env: safeEnv('gemini'), timeoutMs });
    const start = r.stdout.indexOf('{"conversation');
    const envelope = extractJson(start >= 0 ? r.stdout.slice(start) : r.stdout);
    return { r, envelope };
  };

  let { r, envelope } = await attempt(prompt);
  let response = envelope?.response || '';
  if (!response && envelope?.denied_actions?.length) {
    ({ r, envelope } = await attempt(`${prompt}\n\nIMPORTANT: do NOT call any tool of any kind. Everything you need is in this message. Answer with the JSON object only.`));
    response = envelope?.response || '';
  }
  if (!envelope || !response) throw new Error(`Gemini CLI ${role} produced no answer (exit ${r.code}): ${redact(`${r.stderr}${r.stdout}`).slice(-500)}`);
  const data = extractJson(response);
  if (!data) throw new Error(`Gemini CLI ${role} answer was not JSON: ${redact(response).slice(0, 400)}`);
  return { data, raw: redact(response) };
}

// ---------------------------------------------------------------- OpenCode (implementer)

export const OPENCODE_DENY = [
  'rm', 'rm *', 'rmdir*', 'mv /*', 'sudo*', 'su *', 'chmod*', 'chown*', 'kill*', 'pkill*', 'killall*',
  'git push*', 'git reset*', 'git clean*', 'git checkout*', 'git switch*', 'git branch*', 'git worktree*', 'git remote*', 'git config*', 'git rebase*', 'git merge*', 'git stash*', 'git tag*',
  'docker*', 'docker-compose*', 'curl*', 'wget*', 'ssh*', 'scp*', 'rsync*', 'nc *', 'ncat*',
  'composer*', 'npm*', 'npx*', 'yarn*', 'pnpm*', 'pip*',
  'php artisan*', 'php *artisan*', 'psql*', 'mysql*', 'redis-cli*',
];

export function openCodeConfig() {
  return {
    permission: {
      edit: 'allow',
      webfetch: 'deny',
      external_directory: 'deny',
      read: { '*': 'allow', '*.env': 'deny', '*.env.*': 'deny', '.env': 'deny', '.env.*': 'deny' },
      bash: { '*': 'allow', ...Object.fromEntries(OPENCODE_DENY.map((p) => [p, 'deny'])), 'php -l *': 'allow' },
    },
    mcp: { 'flowsync-dev': { type: 'local', command: ['node', MCP_SERVER_ENTRY], enabled: true } },
  };
}

/** OpenCode implements inside the task's worktree, with explicit deny rules for destructive/escaping commands. */
export async function runOpenCode({ prompt, cwd, timeoutMs = 900000 }) {
  if (process.env.FLOWSYNC_FAKE_AGENTS_DIR) return { text: await fakeAgent('implement', prompt, cwd) };

  const model = process.env.FLOWSYNC_OPENCODE_MODEL || 'opencode/nemotron-3.5-lightning-free';
  const args = ['run', '--pure', '--auto', '-m', model, '--dir', cwd, '--title', `flowsync ${path.basename(cwd)}`, prompt];
  const r = await runProcess('opencode', args, { cwd, env: safeEnv('opencode', { OPENCODE_CONFIG_CONTENT: JSON.stringify(openCodeConfig()) }), timeoutMs });
  const text = redact(`${r.stdout}${r.stderr ? `\n[stderr]\n${r.stderr}` : ''}`);
  if (r.timedOut) throw new Error(`OpenCode timed out after ${Math.round(timeoutMs / 1000)}s.`);
  if (r.code !== 0) throw new Error(`OpenCode exited with ${r.code}: ${text.slice(-600)}`);
  return { text };
}
