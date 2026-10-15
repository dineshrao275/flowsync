import { spawn, execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { PROJECT_ROOT } from './paths.mjs';
import { redact } from './redact.mjs';
import { acquireLock } from './lock.mjs';

export const IMAGE = process.env.FLOWSYNC_DOCKER_IMAGE || 'flowsync:latest';
const MAX_CAPTURE = 4 * 1024 * 1024;

const TEST_PATH_RE = /^tests\/[A-Za-z0-9_./-]+\.php$/;
const TEST_FILTER_RE = /^[A-Za-z0-9_|\\:. -]{1,200}$/;
const PINT_PATH_RE = /^[A-Za-z0-9_./-]+\.php$/;

/** A test selection is either a repo-relative test file or `filter:<phpunit --filter expression>`. */
export function parseTestEntry(entry) {
  const s = String(entry || '').trim();
  if (s.startsWith('filter:')) {
    const v = s.slice(7).trim();
    if (!TEST_FILTER_RE.test(v)) throw new Error(`Rejected test filter '${v}'.`);
    return { kind: 'filter', value: v, label: s };
  }
  if (!TEST_PATH_RE.test(s) || s.includes('..')) throw new Error(`Rejected test path '${s}': use tests/<path>.php or filter:<Name>.`);
  return { kind: 'path', value: s, label: s };
}

export function dockerAvailable() {
  try {
    execFileSync('docker', ['image', 'inspect', IMAGE], { stdio: 'ignore' });
    return { ok: true };
  } catch {
    return { ok: false, reason: `Docker image '${IMAGE}' is not available. Build it once with: docker compose build app` };
  }
}

/**
 * The container is a sandbox: no network, read-only root and source tree, capabilities dropped,
 * resource-limited, host UID, secrets masked. Only /tmp, storage and bootstrap/cache (tmpfs) are
 * writable — and they vanish with the container. APP_KEY is a throwaway generated per run, never
 * the project's real one.
 */
export function buildDockerArgs({ tree, name, argv }) {
  const uid = process.getuid?.() ?? 1000;
  const gid = process.getgid?.() ?? 1000;
  const args = [
    'run', '--rm', '--name', name,
    '--network', 'none',
    '--read-only',
    '--cap-drop', 'ALL',
    '--security-opt', 'no-new-privileges',
    '--pids-limit', '512', '--memory', '3g', '--cpus', '2',
    '--user', `${uid}:${gid}`,
    '--tmpfs', '/tmp:rw,exec,size=768m,mode=1777',
    '--tmpfs', `/app/storage:rw,size=256m,uid=${uid},gid=${gid}`,
    '--tmpfs', `/app/bootstrap/cache:rw,size=64m,uid=${uid},gid=${gid}`,
    '-e', `APP_KEY=base64:${crypto.randomBytes(32).toString('base64')}`,
    '-e', 'APP_ENV=testing',
    '-v', `${tree}:/app:ro`,
  ];
  // A worktree has no vendor/ of its own (gitignored): share the host's, read-only.
  if (!fs.existsSync(path.join(tree, 'vendor')) && fs.existsSync(path.join(PROJECT_ROOT, 'vendor'))) {
    args.push('-v', `${path.join(PROJECT_ROOT, 'vendor')}:/app/vendor:ro`);
  }
  if (fs.existsSync(path.join(tree, '.env'))) args.push('-v', '/dev/null:/app/.env:ro');
  args.push('-w', '/app', IMAGE, 'sh', '-c', 'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs && exec "$@"', 'sh', ...argv);
  return args;
}

function runContainer({ tree, argv, timeoutMs }) {
  const name = `flowsync-agent-${crypto.randomBytes(5).toString('hex')}`;
  return new Promise((resolve) => {
    const child = spawn('docker', buildDockerArgs({ tree, name, argv }), { stdio: ['ignore', 'pipe', 'pipe'] });
    let out = '';
    let timedOut = false;
    const add = (b) => {
      if (out.length < MAX_CAPTURE) out += b.toString();
    };
    child.stdout.on('data', add);
    child.stderr.on('data', add);
    const timer = setTimeout(() => {
      timedOut = true;
      try {
        execFileSync('docker', ['kill', name], { stdio: 'ignore' });
      } catch {}
    }, timeoutMs);
    child.on('close', (code) => {
      clearTimeout(timer);
      resolve({ exitCode: timedOut ? 124 : code ?? 1, timedOut, output: out });
    });
    child.on('error', (e) => {
      clearTimeout(timer);
      resolve({ exitCode: 127, timedOut: false, output: String(e.message) });
    });
  });
}

export function summarisePhpunit(output) {
  const m = output.match(/Tests:\s+(.*?)(?:\(|\n|$)/);
  return m ? m[1].trim().replace(/\s+/g, ' ') : null;
}

const tail = (s, n = 8000) => (s.length > n ? `…${s.slice(-n)}` : s);

/**
 * Run the checks for a worktree: every selected test in the sandbox, plus Pint (code style, the
 * project's gate) over the changed PHP files. Serialised across tasks so two agents never fight
 * over the machine.
 * @param {{tree: string, tests: string[], phpFiles?: string[], timeoutMs?: number}} opts
 */
export async function runChecks({ tree, tests, phpFiles = [], timeoutMs = 600000 }) {
  // Self-test only: the verdict is read from a marker file instead of starting a container.
  if (process.env.FLOWSYNC_FAKE_DOCKER === '1') {
    const marker = path.join(tree, '.fake-test-result');
    const verdict = fs.existsSync(marker) ? fs.readFileSync(marker, 'utf-8').trim() : 'pass';
    const passed = verdict === 'pass';
    const entries = tests.map((t) => parseTestEntry(t).label);
    if (entries.length === 0) return { passed: false, infrastructureError: null, runs: [], summary: 'No tests were selected: the plan listed none and the change adds none.' };
    const runs = entries.map((e) => ({ name: `tests: ${e}`, command: `php artisan test ${e}`, exitCode: passed ? 0 : 1, timedOut: false, summary: passed ? '1 passed' : '1 failed', output: passed ? 'OK' : 'FAILED: Failed asserting that false is true.' }));
    return { passed, infrastructureError: null, runs, summary: runs.map((r) => `${passed ? 'PASS' : 'FAIL'} ${r.name} — ${r.summary}`).join('\n') };
  }
  const avail = dockerAvailable();
  if (!avail.ok) return { passed: false, infrastructureError: avail.reason, runs: [], summary: avail.reason };

  const entries = [...new Map(tests.map((t) => [t, parseTestEntry(t)])).values()];
  if (entries.length === 0) {
    return { passed: false, infrastructureError: null, runs: [], summary: 'No tests were selected: the plan listed none and the change adds none.' };
  }

  const release = await acquireLock('docker-checks', { timeoutMs: 30 * 60 * 1000, staleMs: 20 * 60 * 1000, heartbeatMs: 15000 });
  const runs = [];
  try {
    for (const e of entries) {
      const argv = ['php', 'artisan', 'test', '--do-not-cache-result', ...(e.kind === 'filter' ? [`--filter=${e.value}`] : [e.value])];
      const r = await runContainer({ tree, argv, timeoutMs });
      runs.push({
        name: `tests: ${e.label}`,
        command: `php artisan test ${e.kind === 'filter' ? `--filter=${e.value}` : e.value}`,
        exitCode: r.exitCode,
        timedOut: r.timedOut,
        summary: summarisePhpunit(r.output),
        output: redact(tail(r.output)),
      });
    }
    const safeFiles = phpFiles.filter((f) => PINT_PATH_RE.test(f) && !f.includes('..') && fs.existsSync(path.join(tree, f)));
    if (safeFiles.length) {
      const r = await runContainer({ tree, argv: ['php', 'vendor/bin/pint', '--test', ...safeFiles], timeoutMs: 120000 });
      runs.push({ name: 'pint (code style)', command: `pint --test ${safeFiles.length} file(s)`, exitCode: r.exitCode, timedOut: r.timedOut, summary: r.exitCode === 0 ? 'clean' : 'style violations', output: redact(tail(r.output, 4000)) });
    }
  } finally {
    release();
  }

  const passed = runs.every((r) => r.exitCode === 0);
  return {
    passed,
    infrastructureError: null,
    runs,
    summary: runs.map((r) => `${r.exitCode === 0 ? 'PASS' : 'FAIL'} ${r.name}${r.summary ? ` — ${r.summary}` : ''}`).join('\n'),
  };
}
