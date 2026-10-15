import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { PROJECT_ROOT, TASK_ID_RE, WORKTREES_DIR } from './paths.mjs';
import { redact } from './redact.mjs';

/** Every git call is an argument array (never a shell string), so task text can't inject commands. */
export function git(args, { cwd = PROJECT_ROOT, input, allowFail = false } = {}) {
  try {
    return execFileSync('git', args, { cwd, encoding: 'utf-8', input, maxBuffer: 64 * 1024 * 1024, stdio: ['pipe', 'pipe', 'pipe'] }).replace(/\n$/, '');
  } catch (e) {
    if (allowFail) return null;
    throw new Error(`git ${args.join(' ')} failed: ${String(e.stderr || e.message).trim()}`);
  }
}

export const branchFor = (taskId) => `agent-workflow/${taskId}`;
export const worktreeFor = (taskId) => path.join(WORKTREES_DIR, taskId);

function assertInsideWorktrees(dir) {
  const resolved = path.resolve(dir);
  if (!resolved.startsWith(`${WORKTREES_DIR}${path.sep}`)) {
    throw new Error(`Refusing to operate outside ${WORKTREES_DIR}: ${resolved}`);
  }
}

/**
 * Give a task its own checkout. The user's working tree is never switched or modified: the task
 * runs on branch agent-workflow/<id> in .agents/workflow/worktrees/<id>, based on the committed
 * HEAD at creation time. Existing branches/worktrees are RESUMED, never reset (no -B, no --force).
 */
export function ensureWorktree(taskId, existing = {}) {
  if (!TASK_ID_RE.test(taskId)) throw new Error(`Invalid task id '${taskId}'`);
  const branch = branchFor(taskId);
  const dir = worktreeFor(taskId);
  fs.mkdirSync(WORKTREES_DIR, { recursive: true });

  const registered = git(['worktree', 'list', '--porcelain'], { allowFail: true }) || '';
  const hasWorktree = registered.split('\n').some((l) => l === `worktree ${dir}`) && fs.existsSync(dir);
  const branchExists = git(['rev-parse', '--verify', '--quiet', `refs/heads/${branch}`], { allowFail: true }) !== null;

  if (!hasWorktree) {
    if (branchExists) {
      git(['worktree', 'add', dir, branch]); // resume existing work
    } else {
      const base = existing.baseCommit || git(['rev-parse', 'HEAD']);
      git(['worktree', 'add', '-b', branch, dir, base]);
    }
  }
  const baseCommit = existing.baseCommit || git(['merge-base', branch, 'HEAD'], { allowFail: true }) || git(['rev-parse', branch]);
  return { branch, worktree: dir, baseCommit };
}

export function removeWorktree(taskId, { force = false } = {}) {
  const dir = worktreeFor(taskId);
  assertInsideWorktrees(dir);
  if (fs.existsSync(dir)) git(['worktree', 'remove', ...(force ? ['--force'] : []), dir]);
  git(['worktree', 'prune']);
}

/** Stage everything in the worktree (so new files show up in diffs) and list what changed vs base. */
export function changedFiles(worktree, baseCommit) {
  assertInsideWorktrees(worktree);
  git(['add', '-A'], { cwd: worktree });
  const out = git(['diff', '--cached', '--name-status', '--no-renames', '-z', baseCommit], { cwd: worktree });
  if (!out) return [];
  const parts = out.split('\0').filter(Boolean);
  const files = [];
  for (let i = 0; i + 1 < parts.length; i += 2) files.push({ status: parts[i][0], path: parts[i + 1] });
  return files;
}

export function diffAgainstBase(worktree, baseCommit, { maxBytes = 200_000, paths = [] } = {}) {
  assertInsideWorktrees(worktree);
  git(['add', '-A'], { cwd: worktree });
  const out = git(['diff', '--cached', baseCommit, ...(paths.length ? ['--', ...paths] : [])], { cwd: worktree });
  const clipped = out.length > maxBytes ? `${out.slice(0, maxBytes)}\n… [diff truncated at ${maxBytes} bytes]` : out;
  return redact(clipped);
}

export function diffStat(worktree, baseCommit) {
  assertInsideWorktrees(worktree);
  git(['add', '-A'], { cwd: worktree });
  return git(['diff', '--cached', '--stat', baseCommit], { cwd: worktree });
}

/** Undo the agent's changes to specific files (paths must stay inside the worktree). */
export function revertPaths(worktree, baseCommit, paths) {
  assertInsideWorktrees(worktree);
  for (const p of paths) {
    const abs = path.resolve(worktree, p);
    if (!abs.startsWith(`${path.resolve(worktree)}${path.sep}`)) continue;
    const trackedAtBase = git(['cat-file', '-e', `${baseCommit}:${p}`], { cwd: worktree, allowFail: true }) !== null;
    if (trackedAtBase) {
      git(['checkout', baseCommit, '--', p], { cwd: worktree });
    } else {
      git(['rm', '-r', '-f', '--cached', '--ignore-unmatch', '--', p], { cwd: worktree });
      fs.rmSync(abs, { recursive: true, force: true });
    }
  }
}

export function commitAll(worktree, message) {
  assertInsideWorktrees(worktree);
  git(['add', '-A'], { cwd: worktree });
  const staged = git(['diff', '--cached', '--name-only'], { cwd: worktree });
  if (!staged) return git(['rev-parse', 'HEAD'], { cwd: worktree });
  git(['-c', 'user.name=FlowSync Agent Workflow', '-c', 'user.email=agent-workflow@localhost', 'commit', '-m', message], { cwd: worktree });
  return git(['rev-parse', 'HEAD'], { cwd: worktree });
}

export function headOf(worktree) {
  return git(['rev-parse', 'HEAD'], { cwd: worktree });
}
