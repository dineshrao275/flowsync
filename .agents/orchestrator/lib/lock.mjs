import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { LOCKS_DIR } from './paths.mjs';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function pidAlive(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch (e) {
    return e.code === 'EPERM';
  }
}

function readOwner(dir) {
  try {
    return JSON.parse(fs.readFileSync(path.join(dir, 'owner.json'), 'utf-8'));
  } catch {
    return null;
  }
}

function isStale(dir, staleMs) {
  const owner = readOwner(dir);
  if (!owner) {
    // A lock dir with no owner file is either mid-creation or abandoned; give it a moment.
    try {
      return Date.now() - fs.statSync(dir).mtimeMs > 5000;
    } catch {
      return true;
    }
  }
  if (owner.host === os.hostname() && !pidAlive(owner.pid)) return true;
  let beat = owner.ts;
  try {
    beat = Math.max(beat, fs.statSync(path.join(dir, 'owner.json')).mtimeMs);
  } catch {}
  return Date.now() - beat > staleMs;
}

/**
 * Acquire a named cross-process lock (atomic mkdir). Returns a release function.
 * A lock whose owner process is dead, or that has not been touched for `staleMs`, is taken over.
 */
export async function acquireLock(name, { timeoutMs = 15000, staleMs = 60000, heartbeatMs = 0 } = {}) {
  fs.mkdirSync(LOCKS_DIR, { recursive: true });
  const dir = path.join(LOCKS_DIR, `${name}.lock`);
  const started = Date.now();

  for (;;) {
    try {
      fs.mkdirSync(dir);
      const write = () => fs.writeFileSync(path.join(dir, 'owner.json'), JSON.stringify({ pid: process.pid, host: os.hostname(), ts: Date.now(), name }));
      write();
      let timer = null;
      if (heartbeatMs > 0) {
        timer = setInterval(() => {
          try {
            write();
          } catch {}
        }, heartbeatMs);
        timer.unref?.();
      }
      let released = false;
      return () => {
        if (released) return;
        released = true;
        if (timer) clearInterval(timer);
        fs.rmSync(dir, { recursive: true, force: true });
      };
    } catch (e) {
      if (e.code !== 'EEXIST') throw e;
      if (isStale(dir, staleMs)) {
        fs.rmSync(dir, { recursive: true, force: true });
        continue;
      }
      if (Date.now() - started > timeoutMs) {
        const owner = readOwner(dir);
        throw new Error(`Timed out waiting for lock '${name}' held by pid ${owner?.pid ?? '?'}`);
      }
      await sleep(40 + Math.random() * 60);
    }
  }
}

export async function withLock(name, fn, opts) {
  const release = await acquireLock(name, opts);
  try {
    return await fn();
  } finally {
    release();
  }
}

/**
 * A task lease: held for as long as one orchestrator process works on a task, so two processes
 * (or an orchestrator and a human running `resume`) can never drive the same task at once.
 */
export async function acquireTaskLease(taskId) {
  try {
    return await acquireLock(`task-${taskId}`, { timeoutMs: 0, staleMs: 90000, heartbeatMs: 15000 });
  } catch (e) {
    throw new Error(`Task '${taskId}' is already being worked on by another process (${e.message}).`);
  }
}
