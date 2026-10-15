import fs from 'node:fs';
import path from 'node:path';
import { EVENTS_FILE, PROGRESS_FILE, REPORTS_DIR, STATE_FILE, TASKS_DIR, TASK_ID_RE, WORKFLOW_DIR } from './paths.mjs';
import { withLock } from './lock.mjs';
import { redactDeep } from './redact.mjs';

export const SCHEMA_VERSION = 2;

/**
 * Allowed task status transitions. The MCP server and the orchestrator both go through this
 * table, so a tool called at the wrong moment (a review before any test ran, a task "passed" by
 * an agent) is refused instead of silently corrupting the workflow.
 */
export const TRANSITIONS = {
  pending: ['planned', 'failed', 'aborted'],
  planned: ['validated', 'needs_plan_revision', 'failed', 'aborted'],
  needs_plan_revision: ['planned', 'failed', 'aborted'],
  validated: ['implementing', 'aborted'],
  implementing: ['implemented', 'needs_revision', 'failed', 'aborted'],
  implemented: ['testing', 'aborted'],
  testing: ['tested', 'failed', 'aborted'],
  tested: ['reviewing', 'aborted'],
  reviewing: ['needs_revision', 'awaiting_approval', 'passed', 'failed', 'aborted'],
  needs_revision: ['implementing', 'failed', 'aborted'],
  awaiting_approval: ['passed', 'needs_revision', 'failed', 'aborted'],
  passed: [],
  failed: ['implementing', 'planned', 'pending'], // a human may reopen a failed task
  aborted: [],
};

export const TERMINAL = new Set(['passed', 'aborted']);
export const ALL_STATUSES = Object.keys(TRANSITIONS);

export function emptyState() {
  return { version: SCHEMA_VERSION, currentTaskId: null, tasks: {}, updatedAt: new Date().toISOString() };
}

export function newTask({ id, goal, maxRetries = 3, title = null }) {
  const now = new Date().toISOString();
  return {
    id,
    title: title || goal.slice(0, 80),
    goal,
    status: 'pending',
    branch: null,
    worktree: null,
    baseCommit: null,
    commit: null,
    plan: null,
    validation: null,
    planRevisions: 0,
    attempts: [], // [{ n, startedAt, endedAt, implementation, checks, review }]
    retryCount: 0,
    maxRetries,
    lastFeedback: null,
    approval: null, // { required, reasons[], state: pending|approved|rejected, by, at, note }
    claims: [],
    lastError: null,
    createdAt: now,
    updatedAt: now,
    history: [],
  };
}

export function assertTaskId(id) {
  if (typeof id !== 'string' || !TASK_ID_RE.test(id)) {
    throw new Error(`Invalid task id '${id}'. Use 2-48 chars: lowercase letters, digits and dashes, starting with a letter or digit.`);
  }
}

export function canTransition(from, to) {
  return from === to || (TRANSITIONS[from] || []).includes(to);
}

function readStateFile() {
  try {
    const raw = JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8'));
    if (raw.version !== SCHEMA_VERSION) {
      throw new Error(`state.json has schema version ${raw.version}; expected ${SCHEMA_VERSION}. Archive it and start fresh.`);
    }
    return raw;
  } catch (e) {
    if (e.code === 'ENOENT') return emptyState();
    throw e;
  }
}

function atomicWrite(file, content) {
  const tmp = `${file}.${process.pid}.${Date.now()}.tmp`;
  fs.writeFileSync(tmp, content, 'utf-8');
  fs.renameSync(tmp, file);
}

function ensureDirs() {
  for (const d of [WORKFLOW_DIR, TASKS_DIR, REPORTS_DIR]) fs.mkdirSync(d, { recursive: true });
}

/** Read-only snapshot (no lock needed: writes are atomic renames). */
export function getState() {
  ensureDirs();
  return readStateFile();
}

export function getTask(id) {
  return getState().tasks[id] || null;
}

/**
 * The only way state changes. Runs `fn(state)` under the state lock; `fn` mutates and may return a
 * value. Everything persisted is secret-redacted, the event log is appended to, task mirrors and
 * PROGRESS.md are regenerated — all before the lock is released.
 */
export async function mutateState(fn, { event } = {}) {
  ensureDirs();
  return withLock('state', async () => {
    const state = readStateFile();
    const before = JSON.stringify(state);
    const result = await fn(state);
    if (JSON.stringify(state) === before && !event) return result;

    const clean = redactDeep(state);
    clean.updatedAt = new Date().toISOString();
    atomicWrite(STATE_FILE, JSON.stringify(clean, null, 2));
    for (const [id, task] of Object.entries(clean.tasks)) {
      atomicWrite(path.join(TASKS_DIR, `${id}.json`), JSON.stringify(task, null, 2));
    }
    if (event) {
      fs.appendFileSync(EVENTS_FILE, `${JSON.stringify(redactDeep({ ts: clean.updatedAt, ...event }))}\n`);
    }
    atomicWrite(PROGRESS_FILE, renderProgress(clean));
    return result;
  });
}

export async function createTask(spec, actor = 'orchestrator') {
  assertTaskId(spec.id);
  return mutateState(
    (state) => {
      if (state.tasks[spec.id]) return state.tasks[spec.id];
      const task = newTask(spec);
      task.history.push({ ts: task.createdAt, status: 'pending', actor, note: 'Task created' });
      state.tasks[spec.id] = task;
      state.currentTaskId = spec.id;
      return task;
    },
    { event: { taskId: spec.id, actor, type: 'task.created' } },
  );
}

/**
 * Patch a task and/or move it to a new status. Status moves must be allowed by TRANSITIONS.
 * `patch` may be an object or a function(task) that mutates the task in place.
 */
export async function updateTask(id, { patch = null, status = null, note = '', actor = 'orchestrator' } = {}) {
  return mutateState(
    (state) => {
      const task = state.tasks[id];
      if (!task) throw new Error(`Task '${id}' not found.`);
      if (status && !canTransition(task.status, status)) {
        throw new Error(`Illegal transition for '${id}': ${task.status} -> ${status}. Allowed: ${(TRANSITIONS[task.status] || []).join(', ') || 'none (terminal)'}.`);
      }
      if (typeof patch === 'function') patch(task);
      else if (patch) Object.assign(task, patch);
      const from = task.status;
      if (status) task.status = status;
      task.updatedAt = new Date().toISOString();
      if (status && status !== from) {
        task.history.push({ ts: task.updatedAt, status, actor, note: note || `${from} -> ${status}` });
      } else if (note) {
        task.history.push({ ts: task.updatedAt, status: task.status, actor, note });
      }
      state.currentTaskId = id;
      return task;
    },
    { event: { taskId: id, actor, type: status ? 'task.status' : 'task.updated', to: status, note } },
  ).then(() => getTask(id));
}

function tail(s, n) {
  return s && s.length > n ? `…${s.slice(-n)}` : s || '';
}

export function renderProgress(state) {
  const tasks = Object.values(state.tasks).sort((a, b) => (a.updatedAt < b.updatedAt ? 1 : -1));
  const pending = tasks.filter((t) => t.status === 'awaiting_approval');
  const rows = tasks.map((t) => {
    const last = t.attempts[t.attempts.length - 1];
    const tests = last?.checks ? (last.checks.passed ? 'pass' : 'FAIL') : '-';
    const review = last?.review?.verdict || '-';
    return `| \`${t.id}\` | ${t.status} | ${t.branch ? `\`${t.branch}\`` : '-'} | ${t.attempts.length}/${t.maxRetries + 1} | ${tests} | ${review} | ${t.updatedAt} |`;
  });
  return [
    '# Agentic workflow progress',
    '',
    `_Generated automatically — do not edit. Updated ${state.updatedAt}._`,
    '',
    pending.length ? `**Waiting for a human decision:** ${pending.map((t) => `\`${t.id}\``).join(', ')} — see \`npm run agent:status\`.\n` : '',
    '| Task | Status | Branch | Attempts | Last tests | Last review | Updated |',
    '|---|---|---|---|---|---|---|',
    ...rows,
    '',
    ...tasks.slice(0, 5).flatMap((t) => [`## \`${t.id}\` — ${t.title}`, `Goal: ${tail(t.goal, 300)}`, ...(t.lastError ? [`Last error: ${tail(t.lastError, 300)}`] : []), '']),
  ].join('\n');
}
