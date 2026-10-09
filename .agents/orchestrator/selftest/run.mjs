#!/usr/bin/env node
/**
 * Self-test of the workflow's control logic with stub agents (no model, no Docker). Proves: the happy
 * path, the correction loop and the feedback it carries, the retry limit, resume after the orchestrator
 * is killed mid-run, the human-approval gate, forbidden-path reversion, secret redaction, plan
 * revision, validator rejection, file-claim blocking, lock contention, concurrent tasks, the MCP
 * server's guards, and that the user's working tree is never touched.
 *
 *   node .agents/orchestrator/selftest/run.mjs
 */
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname(fileURLToPath(import.meta.url));
const ORCH = path.resolve(here, '../orchestrator.mjs');
const MCP = path.resolve(here, '../mcp-server/index.js');
const STUBS = path.join(here, 'stubs');

let failures = 0;
let checks = 0;
const ok = (cond, msg) => {
  checks++;
  if (cond) console.log(`  ✔ ${msg}`);
  else {
    failures++;
    console.log(`  ✖ ${msg}`);
  }
};
const eq = (a, b, msg) => ok(JSON.stringify(a) === JSON.stringify(b), `${msg}${JSON.stringify(a) === JSON.stringify(b) ? '' : ` (got ${JSON.stringify(a)}, expected ${JSON.stringify(b)})`}`);

function sh(cmd, args, cwd) {
  const r = spawnSync(cmd, args, { cwd, encoding: 'utf-8' });
  if (r.status !== 0) throw new Error(`${cmd} ${args.join(' ')}: ${r.stderr}`);
  return r.stdout.trim();
}

function makeProject() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'flowsync-selftest-'));
  sh('git', ['init', '-q', root], root);
  sh('git', ['symbolic-ref', 'HEAD', 'refs/heads/main'], root);
  fs.writeFileSync(path.join(root, '.gitignore'), '.env\n.agents/workflow/\nvendor/\n.fake-state*.json\n');
  fs.writeFileSync(path.join(root, 'README.md'), '# fake project\n');
  fs.writeFileSync(path.join(root, 'AGENTS.md'), '# fake agents file\n');
  fs.writeFileSync(path.join(root, '.env'), 'APP_KEY=base64:REALSECRETKEYREALSECRETKEYREALSECRETKEY00=\n'); // untracked, must never leak
  fs.mkdirSync(path.join(root, 'app'));
  fs.writeFileSync(path.join(root, 'app/.gitkeep'), '');
  fs.mkdirSync(path.join(root, 'tests'));
  fs.writeFileSync(path.join(root, 'tests/.gitkeep'), '');
  sh('git', ['add', '-A'], root);
  sh('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init'], root);
  return root;
}

function setScenario(root, scenario) {
  const f = path.join(root, '.fake-state.json');
  fs.writeFileSync(f, JSON.stringify({ scenario, counters: {}, prompts: {} }));
  return f;
}
const fake = (f) => JSON.parse(fs.readFileSync(f, 'utf-8'));

function env(root, stateFile) {
  return { PATH: process.env.PATH, HOME: process.env.HOME, FLOWSYNC_PROJECT_ROOT: root, FLOWSYNC_FAKE_AGENTS_DIR: STUBS, FLOWSYNC_FAKE_STATE: stateFile, FLOWSYNC_FAKE_DOCKER: '1', FLOWSYNC_MAX_PARALLEL_TASKS: '5' };
}
function cli(root, stateFile, args) {
  const r = spawnSync(process.execPath, [ORCH, ...args], { cwd: root, env: env(root, stateFile), encoding: 'utf-8', timeout: 120000 });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}
const state = (root) => JSON.parse(fs.readFileSync(path.join(root, '.agents/workflow/state.json'), 'utf-8'));
const task = (root, id) => state(root).tasks[id];
const branches = (root) => sh('git', ['branch', '--list', 'agent-workflow/*', '--format=%(refname:short)'], root).split('\n').filter(Boolean);

function scenario(name, fn) {
  console.log(`\n▶ ${name}`);
  const root = makeProject();
  try {
    return fn(root);
  } finally {
    try {
      spawnSync('git', ['worktree', 'prune'], { cwd: root });
      fs.rmSync(root, { recursive: true, force: true });
    } catch {}
  }
}

// ---------------------------------------------------------------------------------------------

scenario('1. happy path, in an isolated worktree, user tree untouched', (root) => {
  const sf = setScenario(root, 'happy');
  const headBefore = sh('git', ['rev-parse', 'HEAD'], root);
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'hello-1']);
  const t = task(root, 'hello-1');
  eq(r.code, 0, 'exits 0');
  eq(t.status, 'passed', 'task passed');
  eq(t.attempts.length, 1, 'one attempt');
  ok(t.commit && t.commit.length === 40, 'a commit was made');
  ok(branches(root).includes('agent-workflow/hello-1'), 'work is on branch agent-workflow/hello-1');
  eq(sh('git', ['rev-parse', '--abbrev-ref', 'HEAD'], root), 'main', 'the user\'s branch is unchanged');
  eq(sh('git', ['rev-parse', 'HEAD'], root), headBefore, 'the user\'s HEAD is unchanged');
  eq(sh('git', ['status', '--porcelain'], root), '', 'the user\'s working tree is clean (nothing leaked into it)');
  ok(!fs.existsSync(path.join(root, 'app/Hello.php')), 'the implementation exists only in the task worktree');
  ok(sh('git', ['show', '--stat', '--format=', t.commit], root).includes('app/Hello.php'), 'the commit contains the implementation');
  eq(t.plan.tests, ['tests/Feature/HelloTest.php'], 'the hostile test path in the plan was dropped');
  eq(t.history.map((h) => h.status).filter((x, i, a) => i === 0 || x !== a[i - 1]).slice(0, 5), ['pending', 'planned', 'validated', 'implementing', 'implemented'], 'history follows the pipeline');
  ok(fs.existsSync(path.join(root, '.agents/workflow/reports/hello-1-report.md')), 'task report written');
  ok(fs.readFileSync(path.join(root, '.agents/workflow/PROGRESS.md'), 'utf-8').includes('hello-1'), 'PROGRESS.md updated automatically');
  ok(fs.existsSync(path.join(root, '.agents/workflow/events.jsonl')), 'event log written');
  const c = fake(sf).counters;
  eq([c.plan, c.validate, c.implement, c.review], [1, 1, 1, 1], 'each agent ran exactly once');
});

scenario('2. correction loop: failing tests -> review report -> fix -> pass', (root) => {
  const sf = setScenario(root, 'correction');
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'fix-loop']);
  const t = task(root, 'fix-loop');
  eq(r.code, 0, 'exits 0 after the correction');
  eq(t.attempts.length, 2, 'two attempts');
  eq(t.retryCount, 1, 'one retry counted');
  eq(t.attempts[0].checks.passed, false, 'attempt 1 failed the sandboxed checks');
  eq(t.attempts[0].review.verdict, 'changes_requested', 'attempt 1 review requested changes');
  eq(t.attempts[1].checks.passed, true, 'attempt 2 passed the checks');
  const p2 = fake(sf).prompts['implement-2'];
  ok(p2.includes('MARKER-ISSUE-ONE'), 'the structured review issue was sent back to the implementer');
  ok(p2.includes('correction attempt 2'), 'the implementer was told it is a correction attempt');
  ok(fs.existsSync(path.join(root, '.agents/workflow/reports/fix-loop-attempt-1-review.json')), 'the review report is persisted for attempt 1');
});

scenario('3. retry limit: stops, marks failed, never commits', (root) => {
  const sf = setScenario(root, 'exhaust');
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'never-ok', '--retries', '1']);
  const t = task(root, 'never-ok');
  eq(r.code, 2, 'exits 2');
  eq(t.status, 'failed', 'failed after the retry limit');
  eq(t.attempts.length, 2, '1 attempt + 1 correction');
  ok(!t.commit, 'nothing was committed');
  ok(t.history.some((h) => /Retry limit/.test(h.note)), 'the reason is recorded');
});

scenario('4. resume after the orchestrator is killed mid-run', (root) => {
  const sf = setScenario(root, 'interrupt-review');
  const r1 = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'resumable']);
  ok(r1.code !== 0, 'the first run died (SIGKILL during review)');
  const mid = task(root, 'resumable');
  eq(mid.status, 'reviewing', 'state on disk says reviewing');
  ok(fs.existsSync(path.join(root, '.agents/workflow/locks/task-resumable.lock')), 'a stale task lease was left behind by the dead process');
  const r2 = cli(root, sf, ['resume', '--id', 'resumable']);
  const t = task(root, 'resumable');
  eq(r2.code, 0, 'resume completes (took over the stale lease)');
  eq(t.status, 'passed', 'task passed');
  const c = fake(sf).counters;
  eq([c.plan, c.validate, c.implement, c.review], [1, 1, 1, 2], 'only the interrupted stage was repeated');
});

scenario('5. risky change (migration) pauses for human approval', (root) => {
  const sf = setScenario(root, 'approval');
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'risky']);
  let t = task(root, 'risky');
  eq(r.code, 3, 'exits 3 (waiting for a human)');
  eq(t.status, 'awaiting_approval', 'status awaiting_approval');
  ok(t.approval.reasons.some((x) => /migration/.test(x.rule)) && t.approval.reasons.some((x) => /destructive/.test(x.rule)), 'reasons name the migration and the destructive statement');
  ok(!t.commit, 'not committed before approval');
  const a = cli(root, sf, ['approve', '--id', 'risky', '--note', 'checked']);
  t = task(root, 'risky');
  eq(a.code, 0, 'approve exits 0');
  eq(t.status, 'passed', 'passed after approval');
  eq(t.approval.state, 'approved', 'approval recorded');
  ok(t.commit && sh('git', ['show', '--stat', '--format=', t.commit], root).includes('drop_old_column'), 'committed on the task branch');
  eq(sh('git', ['rev-parse', '--abbrev-ref', 'HEAD'], root), 'main', 'still nothing merged or switched');
});

scenario('6. forbidden paths are reverted and reported', (root) => {
  const sf = setScenario(root, 'forbidden');
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'sneaky']);
  const t = task(root, 'sneaky');
  eq(r.code, 0, 'passes once the second attempt is clean');
  eq(t.attempts.length, 2, 'attempt 1 was rejected');
  ok(t.attempts[0].risk.forbidden.some((f) => f.path === '.env.production'), 'the .env.production edit was detected');
  ok(t.attempts[0].risk.forbidden.some((f) => f.path.startsWith('.agents/orchestrator/')), 'the edit to the orchestration layer was detected');
  eq(t.attempts[0].review.verdict, 'changes_requested', 'an approving reviewer was overridden');
  const files = sh('git', ['show', '--stat', '--format=', t.commit], root);
  ok(!files.includes('.env.production') && !files.includes('evil.json'), 'the final commit contains neither');
});

scenario('7. credential-like strings block the attempt and never reach state or reports', (root) => {
  const sf = setScenario(root, 'secret');
  cli(root, sf, ['--task', 'Add a hello helper', '--id', 'leaky']);
  const t = task(root, 'leaky');
  eq(t.attempts[0].checks.passed, false, 'attempt 1: checks not run/failed');
  ok(/credential-like/.test(t.attempts[0].checks.summary), 'the summary says why');
  eq(t.status, 'passed', 'attempt 2 (secret removed) passed');
  const blob = ['state.json', 'events.jsonl', 'PROGRESS.md', ...fs.readdirSync(path.join(root, '.agents/workflow/reports')).map((f) => `reports/${f}`)]
    .map((f) => fs.readFileSync(path.join(root, '.agents/workflow', f), 'utf-8')).join('\n');
  const dummySecret = ['sk', 'test', 'sampletestdummykey12345678'].join('_');
  ok(!blob.includes(dummySecret), 'the secret is absent from state, events and every report');
  ok(!blob.includes('REALSECRETKEY'), 'the project\'s real .env value is absent too');
});

scenario('8. validator gaps trigger exactly one plan revision', (root) => {
  const sf = setScenario(root, 'gaps');
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'revised']);
  const t = task(root, 'revised');
  eq(r.code, 0, 'passes');
  eq(t.planRevisions, 1, 'one plan revision');
  const c = fake(sf).counters;
  eq([c.plan || 0, c.revise, c.validate], [1, 1, 2], 'plan once, revise once, validate twice');
  ok(fake(sf).prompts['revise-1'].includes('No test for unauthenticated users'), 'the validator\'s gap was handed to the planner');
});

scenario('9. a validator rejection stops the task before any code is written', (root) => {
  const sf = setScenario(root, 'reject');
  const r = cli(root, sf, ['--task', 'Add a hello helper', '--id', 'declined']);
  const t = task(root, 'declined');
  eq(r.code, 2, 'exits 2');
  eq(t.status, 'failed', 'failed');
  eq(fake(sf).counters.implement || 0, 0, 'the implementer never ran');
});

scenario('10. overlapping file claims block a second task; tasks otherwise run concurrently and state stays valid', (root) => {
  const sf = setScenario(root, 'conflict');
  // Task A is mid-flight (planned, claiming app/Shared.php) when B plans the same file.
  const a = cli(root, sf, ['--task', 'A', '--id', 'task-a']); // runs to completion
  eq(task(root, 'task-a').status, 'passed', 'task A finished');
  // finished tasks do not hold claims; now make an active one by hand
  const stateFile = path.join(root, '.agents/workflow/state.json');
  const s = JSON.parse(fs.readFileSync(stateFile, 'utf-8'));
  s.tasks['task-a'].status = 'implementing';
  fs.writeFileSync(stateFile, JSON.stringify(s));
  const b = cli(root, sf, ['--task', 'B', '--id', 'task-b']);
  eq(b.code, 4, 'task B is blocked (exit 4)');
  ok(/overlap/.test(b.out) && /task-a/.test(b.out), 'the message names the conflicting task');
  eq(task(root, 'task-b').status, 'planned', 'B waits in planned and can be resumed later');
});

scenario('11. a task can only be driven by one process at a time', (root) => {
  const sf = setScenario(root, 'happy');
  const child = spawn(process.execPath, ['-e', `
    import('${path.resolve(here, '../lib/lock.mjs').replace(/\\/g, '/')}').then(async (m) => { await m.acquireTaskLease('held'); process.stdout.write('leased\\n'); setInterval(() => {}, 1000); });
  `], { env: env(root, sf), stdio: ['ignore', 'pipe', 'inherit'] });
  return new Promise((resolve) => {
    child.stdout.once('data', () => {
      // create the task record so only the lease is in the way
      spawnSync(process.execPath, ['-e', `import('${path.resolve(here, '../lib/state.mjs').replace(/\\/g, '/')}').then((m) => m.createTask({ id: 'held', goal: 'x' }))`], { env: env(root, sf) });
      const r = cli(root, sf, ['resume', '--id', 'held']);
      ok(r.code === 1 && /already being worked on/.test(r.out), 'a second driver is refused while the lease is held');
      child.kill('SIGKILL');
      resolve();
    });
  });
});

console.log('\n▶ 12. concurrent tasks write the shared state without corruption');
{
  const root = makeProject();
  const sf1 = setScenario(root, 'parallel');
  const sf2 = path.join(root, '.fake-state-2.json');
  fs.writeFileSync(sf2, JSON.stringify({ scenario: 'parallel', counters: {}, prompts: {} }));
  const launch = (id, sf) => new Promise((res) => {
    const c = spawn(process.execPath, [ORCH, '--task', `Hello ${id}`, '--id', id], { cwd: root, env: env(root, sf), stdio: 'ignore' });
    c.on('close', (code) => res(code));
  });
  const codes = await Promise.all([launch('par-a', sf1), launch('par-b', sf2), launch('par-c', sf2)]);
  eq(codes, [0, 0, 0], 'three tasks ran in parallel and all passed');
  const s = state(root);
  eq(Object.keys(s.tasks).sort(), ['par-a', 'par-b', 'par-c'], 'state.json is valid and holds all three tasks');
  eq(branches(root).sort(), ['agent-workflow/par-a', 'agent-workflow/par-b', 'agent-workflow/par-c'], 'each task has its own branch');
  eq(new Set(['par-a', 'par-b', 'par-c'].map((i) => s.tasks[i].worktree)).size, 3, 'each task has its own worktree (no shared files to overwrite)');
  fs.rmSync(root, { recursive: true, force: true });
}

console.log('\n▶ 13. the MCP server enforces the same rules');
{
  const root = makeProject();
  const sf = setScenario(root, 'happy');
  cli(root, sf, ['--task', 'Add a hello helper', '--id', 'mcp-1']); // gives us a passed task with a worktree
  const req = createRequire(path.resolve(here, '../mcp-server/package.json'));
  const { Client } = await import(req.resolve('@modelcontextprotocol/sdk/client/index.js'));
  const { StdioClientTransport } = await import(req.resolve('@modelcontextprotocol/sdk/client/stdio.js'));
  const client = new Client({ name: 'selftest', version: '1' });
  await client.connect(new StdioClientTransport({ command: process.execPath, args: [MCP], env: env(root, sf), stderr: 'ignore' }));
  const call = async (name, args) => {
    try {
      const r = await client.callTool({ name, arguments: args });
      return { ok: !r.isError, text: r.content?.[0]?.text || '' };
    } catch (e) {
      return { ok: false, text: String(e.message) };
    }
  };
  const tools = (await client.listTools()).tools.map((t) => t.name);
  ok(['flowsync_get_task', 'flowsync_get_task_diff', 'flowsync_run_docker_tests', 'flowsync_submit_task_plan'].every((t) => tools.includes(t)), 'all expected tools are exposed');

  let r = await call('flowsync_update_task_status', { taskId: 'mcp-1', status: 'passed' });
  ok(!r.ok, 'an agent cannot mark a task passed');
  r = await call('flowsync_submit_task_validation', { taskId: 'mcp-1', verdict: 'approved', analysis: 'x' });
  ok(!r.ok && /only accepted while it is 'planned'/.test(r.text), 'validation is refused when the task is not in the planned state');
  r = await call('flowsync_submit_code_review', { taskId: 'mcp-1', verdict: 'approved', summary: 'x' });
  ok(!r.ok, 'a review is refused outside the review stage');
  r = await call('flowsync_run_docker_tests', { tests: ['tests/../.env'] });
  ok(!r.ok && /Rejected test path/.test(r.text), 'a path-traversal test argument is rejected');
  r = await call('flowsync_run_docker_tests', { taskId: 'mcp-1', tests: ['filter:x; rm -rf /'] });
  ok(!r.ok && /Rejected test filter/.test(r.text), 'a shell-metacharacter filter is rejected');
  r = await call('flowsync_get_task_diff', { taskId: 'mcp-1' });
  ok(r.ok && r.text.includes('Hello') && !r.text.includes('REALSECRETKEY'), 'the task diff is readable and secret-free');
  r = await call('flowsync_submit_task_plan', { taskId: 'mcp-new', title: 'T', requirements: 'R', acceptanceCriteria: ['c'], tests: ['tests/Feature/XTest.php'] });
  ok(r.ok, 'a plan can be submitted through MCP');
  r = await call('flowsync_get_project_context', {});
  ok(r.ok && !r.text.includes('REALSECRETKEY'), 'project context is served without secrets');
  await client.close();
  fs.rmSync(root, { recursive: true, force: true });
}

console.log('\n▶ 14. unit checks: redaction and the state machine');
{
  const { redact } = await import('../lib/redact.mjs');
  const samples = ['sk-ant-api03-abcdefghijklmnopqrstuvwxyz', 'sk_live_abcdefghij1234567890', 'whsec_abcdefghijklmnop1234', 'AIzaSyA1234567890abcdefghijklmnopqrstuv', 'ghp_abcdefghijklmnopqrstuvwxyz0123456789', 'base64:' + 'A'.repeat(43) + '=', 'Bearer abcdefghijklmnopqrstuvwxyz012345', 'DB_PASSWORD=hunter2hunter2', 'postgres://user:topsecret@db:5432/x'];
  for (const s of samples) ok(!redact(`x ${s} y`).includes(s.split(/[=:]/).pop().trim()) || redact(`x ${s} y`) !== `x ${s} y`, `redacts: ${s.slice(0, 22)}…`);
  eq(redact('APP_NAME=FlowSync'), 'APP_NAME=FlowSync', 'leaves ordinary config alone');
  eq(redact('DB_PASSWORD=hunter2hunter2'), 'DB_PASSWORD=[REDACTED]', 'keeps the variable name, drops the value');
  const { canTransition } = await import('../lib/state.mjs');
  ok(!canTransition('pending', 'passed') && !canTransition('planned', 'implementing') && !canTransition('passed', 'implementing'), 'illegal transitions are refused');
  ok(canTransition('reviewing', 'passed') && canTransition('awaiting_approval', 'passed'), 'legal transitions are allowed');
}

console.log(`\n${failures === 0 ? '✅' : '❌'} ${checks - failures}/${checks} checks passed${failures ? `, ${failures} FAILED` : ''}`);
process.exit(failures ? 1 : 0);
