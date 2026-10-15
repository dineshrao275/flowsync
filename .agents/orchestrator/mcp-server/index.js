#!/usr/bin/env node

/**
 * FlowSync shared MCP server — the one server Claude Code, Gemini CLI and OpenCode all connect to.
 *
 * It provides shared CONTEXT and TOOLS (project rules, task state, diffs, sandboxed test runs).
 * It does not coordinate agents: handoffs, retries and completion belong to the orchestrator
 * (../orchestrator.mjs), which drives the same task state through the same lib/state.mjs.
 *
 * Safety properties: state changes go through a locked, transition-checked store; test runs use the
 * sandboxed container (no network, read-only source, masked secrets); diffs/tests/state are
 * secret-redacted; no tool can push, merge, deploy, or mark a task `passed`.
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ErrorCode, ListResourcesRequestSchema, ListToolsRequestSchema, McpError, ReadResourceRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import fs from 'node:fs';
import path from 'node:path';
import { PROJECT_ROOT } from '../lib/paths.mjs';
import { redact, redactDeep } from '../lib/redact.mjs';
import { createTask, getState, getTask, TERMINAL, updateTask } from '../lib/state.mjs';
import { changedFiles, diffAgainstBase, git } from '../lib/git.mjs';
import { parseTestEntry, runChecks } from '../lib/docker.mjs';

const server = new Server({ name: 'flowsync-dev', version: '2.0.0' }, { capabilities: { tools: {}, resources: {} } });

const text = (value) => ({ content: [{ type: 'text', text: typeof value === 'string' ? redact(value) : JSON.stringify(redactDeep(value), null, 2) }] });
const bad = (msg) => {
  throw new McpError(ErrorCode.InvalidParams, msg);
};

const TASK_ID = { type: 'string', description: 'Task id: 2-48 chars, lowercase letters/digits/dashes' };

const TOOLS = [
  {
    name: 'flowsync_get_project_context',
    description: 'Get FlowSync architecture, tenancy and testing rules plus pointers to the .agents/ documentation. Read this before planning or implementing.',
    inputSchema: { type: 'object', properties: { topic: { type: 'string', description: 'Optional focus: architecture | tenancy | testing | conventions | security' } } },
  },
  {
    name: 'flowsync_get_workflow_state',
    description: 'List workflow tasks with their status (and the full record of one task if taskId is given).',
    inputSchema: { type: 'object', properties: { taskId: TASK_ID } },
  },
  {
    name: 'flowsync_get_task',
    description: 'Full record of one task: plan, validation, attempts, test results, reviews, the worktree path and the files changed so far.',
    inputSchema: { type: 'object', required: ['taskId'], properties: { taskId: TASK_ID } },
  },
  {
    name: 'flowsync_get_task_diff',
    description: 'The task branch diff against its base commit (secret-redacted, size-capped). Use this to review what was implemented.',
    inputSchema: { type: 'object', required: ['taskId'], properties: { taskId: TASK_ID, maxBytes: { type: 'number', description: 'Cap, default 120000' } } },
  },
  {
    name: 'flowsync_submit_task_plan',
    description: 'Claude: register a task plan (requirements, files, tests, acceptance criteria). Creates the task if it does not exist.',
    inputSchema: {
      type: 'object',
      required: ['taskId', 'title', 'requirements', 'acceptanceCriteria'],
      properties: {
        taskId: TASK_ID,
        title: { type: 'string' },
        requirements: { type: 'string' },
        targetFiles: { type: 'array', items: { type: 'string' } },
        tests: { type: 'array', items: { type: 'string' }, description: "'tests/<path>.php' or 'filter:<Name>'" },
        testCommand: { type: 'string', description: "Legacy: e.g. 'php artisan test tests/Feature/XTest.php'" },
        acceptanceCriteria: { type: 'array', items: { type: 'string' } },
        dependencies: { type: 'array', items: { type: 'string' } },
      },
    },
  },
  {
    name: 'flowsync_submit_task_validation',
    description: 'Gemini: record an independent validation of a planned task (verdict, gaps, edge cases, recommendations). Only valid while the task is `planned`.',
    inputSchema: {
      type: 'object',
      required: ['taskId', 'verdict', 'analysis'],
      properties: {
        taskId: TASK_ID,
        verdict: { type: 'string', enum: ['approved', 'gaps_identified', 'rejected'] },
        analysis: { type: 'string' },
        gapsOrRisks: { type: 'array', items: { type: 'string' } },
        edgeCases: { type: 'array', items: { type: 'string' } },
        recommendations: { type: 'array', items: { type: 'string' } },
      },
    },
  },
  {
    name: 'flowsync_submit_code_review',
    description: 'Claude: record a structured code review of the latest attempt. Only valid after tests ran. Never marks a task passed — the orchestrator finalises that after the risk scan.',
    inputSchema: {
      type: 'object',
      required: ['taskId', 'verdict', 'summary'],
      properties: {
        taskId: TASK_ID,
        verdict: { type: 'string', enum: ['approved', 'changes_requested', 'rejected'] },
        summary: { type: 'string' },
        issuesFound: { type: 'array', items: { type: 'string' } },
        suggestions: { type: 'array', items: { type: 'string' } },
      },
    },
  },
  {
    name: 'flowsync_update_task_status',
    description: 'Move a task to another status. Transitions are validated; `passed` can never be set here.',
    inputSchema: {
      type: 'object',
      required: ['taskId', 'status'],
      properties: { taskId: TASK_ID, status: { type: 'string' }, notes: { type: 'string' } },
    },
  },
  {
    name: 'flowsync_run_docker_tests',
    description: "Run tests (and Pint) for a task's worktree inside the sandboxed Docker container: no network, read-only source, throwaway secrets. This is the ONLY way agents run PHP tests.",
    inputSchema: {
      type: 'object',
      properties: {
        taskId: { ...TASK_ID, description: "Task whose worktree to test. Omit to test the main working tree (read-only)." },
        tests: { type: 'array', items: { type: 'string' }, description: "'tests/<path>.php' or 'filter:<Name>'. Defaults to the plan's tests." },
        testPath: { type: 'string', description: 'Legacy single test path.' },
      },
    },
  },
  {
    name: 'flowsync_get_git_status',
    description: "Read-only git status of the main working tree, or of a task's worktree when taskId is given.",
    inputSchema: { type: 'object', properties: { taskId: TASK_ID, includeDiff: { type: 'boolean' } } },
  },
  {
    name: 'flowsync_list_pending_approvals',
    description: 'Tasks waiting for a human decision (risky changes), with the reasons.',
    inputSchema: { type: 'object', properties: {} },
  },
];

server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOLS }));

function requireTask(id) {
  if (!id) bad('taskId is required.');
  const task = getTask(id);
  if (!task) bad(`Task '${id}' not found.`);
  return task;
}

function normaliseTests({ tests, testCommand }) {
  const out = [...(tests || [])];
  const m = String(testCommand || '').match(/artisan test\s+(tests\/\S+\.php)/);
  if (m) out.push(m[1]);
  const f = String(testCommand || '').match(/--filter[= ](\S+)/);
  if (f) out.push(`filter:${f[1]}`);
  return [...new Set(out)].map((t) => parseTestEntry(t).label);
}

const HANDLERS = {
  async flowsync_get_project_context(args) {
    const agentsMd = path.join(PROJECT_ROOT, 'AGENTS.md');
    const head = fs.existsSync(agentsMd) ? fs.readFileSync(agentsMd, 'utf-8').slice(0, 3000) : '';
    const docs = fs.existsSync(path.join(PROJECT_ROOT, '.agents')) ? fs.readdirSync(path.join(PROJECT_ROOT, '.agents')).filter((f) => f.endsWith('.md')).sort() : [];
    return text({
      project: 'FlowSync',
      stack: 'Laravel 12 + React 19 SPA, PostgreSQL (SQLite file fast-path for tests)',
      tenancy: 'One database per tenant + a central system DB. Tenant DBs have no tenant_id columns; central models use the CentralConnection trait.',
      testing: 'Feature tests use Tests\\IsolatesDatabase. Agents run tests only in the sandboxed Docker container (flowsync_run_docker_tests); Pint must pass on changed PHP files.',
      git: 'Each task works on its own branch agent-workflow/<id> in its own git worktree. Never push, merge or deploy. Migrations, infrastructure and dependency changes need human approval.',
      forbiddenToEdit: ['.env*', '.git/', '.agents/orchestrator/', '.agents/workflow/', 'storage/', 'vendor/', 'node_modules/', '.mcp.json', 'opencode.json'],
      documentation: docs.map((d) => `.agents/${d}`),
      focus: args?.topic || 'general',
      agentsMdExcerpt: head,
    });
  },

  async flowsync_get_workflow_state(args) {
    const state = getState();
    const id = args?.taskId || state.currentTaskId;
    return text({
      currentTaskId: state.currentTaskId,
      task: id ? state.tasks[id] || null : null,
      tasks: Object.values(state.tasks).map((t) => ({ id: t.id, title: t.title, status: t.status, branch: t.branch, attempts: t.attempts.length, updatedAt: t.updatedAt })),
    });
  },

  async flowsync_get_task(args) {
    const task = requireTask(args.taskId);
    let files = [];
    if (task.worktree && task.baseCommit && fs.existsSync(task.worktree)) files = changedFiles(task.worktree, task.baseCommit);
    return text({ ...task, changedFiles: files });
  },

  async flowsync_get_task_diff(args) {
    const task = requireTask(args.taskId);
    if (!task.worktree || !fs.existsSync(task.worktree)) bad(`Task '${task.id}' has no worktree yet.`);
    return text(diffAgainstBase(task.worktree, task.baseCommit, { maxBytes: Math.min(Number(args.maxBytes) || 120000, 400000) }) || '(no changes)');
  },

  async flowsync_submit_task_plan(args) {
    const existing = getTask(args.taskId);
    if (existing && !['pending', 'planned', 'needs_plan_revision'].includes(existing.status)) {
      bad(`Task '${args.taskId}' is '${existing.status}'; a plan can only be (re)submitted before validation.`);
    }
    if (!existing) await createTask({ id: args.taskId, goal: args.requirements, title: args.title }, 'claude');
    const tests = normaliseTests(args);
    const plan = { title: args.title, requirements: args.requirements, targetFiles: args.targetFiles || [], tests, acceptanceCriteria: args.acceptanceCriteria, dependencies: args.dependencies || [], riskNotes: [] };
    await updateTask(args.taskId, { patch: { plan, title: args.title }, status: 'planned', note: 'Plan submitted via MCP', actor: 'claude' });
    return text(`Task '${args.taskId}' planned (${tests.length} test selection(s)).`);
  },

  async flowsync_submit_task_validation(args) {
    const task = requireTask(args.taskId);
    if (task.status !== 'planned') bad(`Task '${task.id}' is '${task.status}'; validation is only accepted while it is 'planned'.`);
    const validation = { verdict: args.verdict, analysis: args.analysis, gaps: args.gapsOrRisks || [], edgeCases: args.edgeCases || [], recommendations: args.recommendations || [], validator: 'gemini', validatedAt: new Date().toISOString() };
    const next = args.verdict === 'approved' ? 'validated' : args.verdict === 'rejected' ? 'failed' : 'needs_plan_revision';
    await updateTask(task.id, { patch: { validation }, status: next, note: `Validation via MCP: ${args.verdict}`, actor: 'gemini' });
    return text(`Validation recorded: ${args.verdict}. Task is now '${next}'.`);
  },

  async flowsync_submit_code_review(args) {
    const task = requireTask(args.taskId);
    if (!['tested', 'reviewing'].includes(task.status)) bad(`Task '${task.id}' is '${task.status}'; a review is only accepted after the tests ran.`);
    const review = { verdict: args.verdict, summary: args.summary, issuesFound: (args.issuesFound || []).map((d) => ({ severity: 'major', description: d })), suggestions: args.suggestions || [], reviewer: 'claude', reviewedAt: new Date().toISOString() };
    const next = args.verdict === 'approved' ? 'reviewing' : args.verdict === 'rejected' ? 'failed' : 'needs_revision';
    await updateTask(
      task.id,
      {
        patch: (t) => {
          const last = t.attempts[t.attempts.length - 1];
          if (last) last.review = review;
          if (next === 'needs_revision') t.lastFeedback = { source: 'review', review };
        },
        status: task.status === 'tested' ? 'reviewing' : undefined,
        note: 'Review via MCP',
        actor: 'claude',
      },
    );
    if (next !== 'reviewing') await updateTask(task.id, { status: next, note: `Review via MCP: ${args.verdict}`, actor: 'claude' });
    return text(`Review recorded: ${args.verdict}. ${next === 'reviewing' ? 'The orchestrator finalises approval after the risk scan.' : `Task is now '${next}'.`}`);
  },

  async flowsync_update_task_status(args) {
    const task = requireTask(args.taskId);
    if (args.status === 'passed') bad("Agents cannot mark a task 'passed': the orchestrator does that after tests, review and the risk scan.");
    if (TERMINAL.has(task.status)) bad(`Task '${task.id}' is already ${task.status}.`);
    const updated = await updateTask(task.id, { status: args.status, note: args.notes || 'Status updated via MCP', actor: 'mcp' });
    return text(`Task '${task.id}' is now '${updated.status}'.`);
  },

  async flowsync_run_docker_tests(args) {
    let tree = PROJECT_ROOT;
    let tests = [...(args.tests || []), ...(args.testPath ? [args.testPath] : [])];
    let task = null;
    if (args.taskId) {
      task = requireTask(args.taskId);
      if (!task.worktree || !fs.existsSync(task.worktree)) bad(`Task '${task.id}' has no worktree yet.`);
      tree = task.worktree;
      if (tests.length === 0) tests = task.plan?.tests || [];
    }
    if (tests.length === 0) bad("No tests given and the task's plan lists none.");
    tests = tests.map((t) => parseTestEntry(t).label); // validates every entry

    const result = await runChecks({ tree, tests, phpFiles: [] });
    if (task) {
      await updateTask(task.id, {
        patch: (t) => {
          const last = t.attempts[t.attempts.length - 1];
          if (last) (last.interimRuns ||= []).push({ at: new Date().toISOString(), tests, passed: result.passed, summary: result.summary });
          if (last && last.interimRuns.length > 10) last.interimRuns.shift();
        },
        note: `Interim test run via MCP: ${result.passed ? 'passed' : 'failed'}`,
        actor: 'mcp',
      });
    }
    return text({ passed: result.passed, summary: result.summary, runs: result.runs.map((r) => ({ name: r.name, exitCode: r.exitCode, summary: r.summary, output: r.exitCode === 0 ? undefined : r.output })) });
  },

  async flowsync_get_git_status(args) {
    let cwd = PROJECT_ROOT;
    if (args?.taskId) {
      const task = requireTask(args.taskId);
      if (!task.worktree || !fs.existsSync(task.worktree)) bad(`Task '${task.id}' has no worktree yet.`);
      cwd = task.worktree;
    }
    const branch = git(['rev-parse', '--abbrev-ref', 'HEAD'], { cwd });
    const status = git(['status', '--short'], { cwd });
    return text({
      currentBranch: branch,
      isProtectedBranch: ['master', 'main', 'development'].includes(branch),
      modifiedFiles: status.split('\n').filter(Boolean),
      diffStat: args?.includeDiff ? git(['diff', '--stat'], { cwd }) : undefined,
    });
  },

  async flowsync_list_pending_approvals() {
    const pending = Object.values(getState().tasks).filter((t) => t.status === 'awaiting_approval');
    return text(pending.map((t) => ({ id: t.id, title: t.title, branch: t.branch, reasons: t.approval?.reasons || [] })));
  },
};

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  const handler = HANDLERS[name];
  if (!handler) throw new McpError(ErrorCode.MethodNotFound, `Unknown tool '${name}'`);
  try {
    return await handler(args || {});
  } catch (err) {
    if (err instanceof McpError) throw err;
    console.error(`[flowsync-mcp] ${name} failed:`, redact(String(err.message)));
    return { isError: true, content: [{ type: 'text', text: redact(`Error executing ${name}: ${err.message}`) }] };
  }
});

server.setRequestHandler(ListResourcesRequestSchema, async () => ({
  resources: [
    { uri: 'flowsync://workflow/state', name: 'FlowSync workflow state', mimeType: 'application/json', description: 'All tasks and their status.' },
    { uri: 'flowsync://workflow/progress', name: 'FlowSync workflow progress', mimeType: 'text/markdown', description: 'The auto-generated progress document.' },
    { uri: 'flowsync://architecture/rules', name: 'FlowSync architecture rules', mimeType: 'text/markdown', description: 'Tenancy, isolation and workflow safety rules.' },
  ],
}));

server.setRequestHandler(ReadResourceRequestSchema, async (request) => {
  const { uri } = request.params;
  if (uri === 'flowsync://workflow/state') return { contents: [{ uri, mimeType: 'application/json', text: JSON.stringify(redactDeep(getState()), null, 2) }] };
  if (uri === 'flowsync://workflow/progress') {
    const f = path.join(PROJECT_ROOT, '.agents/workflow/PROGRESS.md');
    return { contents: [{ uri, mimeType: 'text/markdown', text: fs.existsSync(f) ? fs.readFileSync(f, 'utf-8') : '(no tasks yet)' }] };
  }
  if (uri === 'flowsync://architecture/rules') {
    return {
      contents: [
        {
          uri,
          mimeType: 'text/markdown',
          text: [
            '# FlowSync rules for agents',
            '1. One database per tenant plus a central system DB; tenant DBs have no tenant_id columns.',
            '2. Tests run only in the sandboxed container (flowsync_run_docker_tests); Pint must pass.',
            '3. Work happens on branch agent-workflow/<id> in its own worktree. Never push, merge or deploy.',
            '4. Migrations, infrastructure, dependency and destructive changes need human approval.',
            '5. Never read, write or print secrets; never edit .env*, the orchestration layer, or its state.',
          ].join('\n'),
        },
      ],
    };
  }
  throw new McpError(ErrorCode.InvalidRequest, `Unknown resource URI: ${uri}`);
});

await server.connect(new StdioServerTransport());
console.error('[flowsync-mcp] FlowSync shared MCP server v2 running on stdio');
