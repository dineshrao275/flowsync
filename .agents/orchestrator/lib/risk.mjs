import fs from 'node:fs';
import path from 'node:path';
import { containsSecret } from './redact.mjs';
import { git } from './git.mjs';

/**
 * Change-risk policy applied to every attempt's diff.
 *
 *  - FORBIDDEN: paths an agent must never touch (secrets, the orchestration layer itself, runtime
 *    data). Such changes are reverted automatically and reported back to the implementer.
 *  - APPROVAL: changes that are legitimate but risky (migrations, infrastructure, dependencies,
 *    deletions, dangerous PHP functions, very large diffs). They never block the loop, but the
 *    task will not be marked passed until a human approves it.
 */
const FORBIDDEN = [
  { re: /(^|\/)\.env(\..*)?$/, why: 'environment/secrets file' },
  { re: /^\.git(\/|$)/, why: 'git internals' },
  { re: /^\.agents\/(orchestrator|workflow)(\/|$)/, why: 'the orchestration layer itself and its state' },
  { re: /^(\.mcp\.json|opencode\.json)$/, why: 'agent/MCP configuration' },
  { re: /^storage\//, why: 'runtime storage' },
  { re: /^database\/tenants\//, why: 'tenant database files' },
  { re: /^(vendor|node_modules)\//, why: 'installed dependencies' },
  { re: /(^|\/)(id_rsa|id_ed25519)(\.pub)?$|\.pem$|\.p12$/, why: 'key material' },
];

const APPROVAL_PATHS = [
  { re: /^database\/migrations\//, why: 'database migration' },
  { re: /^(Dockerfile|docker-compose[^/]*\.yml|docker\/)/, why: 'container/infrastructure' },
  { re: /^(composer\.(json|lock)|package(-lock)?\.json)$/, why: 'dependency manifest' },
  { re: /^config\/(database|payments|tenancy|queue|broadcasting|mail|services)\.php$/, why: 'infrastructure configuration' },
  { re: /^\.github\//, why: 'CI configuration' },
  { re: /^(bootstrap\/app\.php|routes\/console\.php)$/, why: 'application bootstrap/scheduler' },
];

const DESTRUCTIVE_SQL = /(dropColumn|dropIfExists|Schema::drop|->drop\(|dropUnique|dropIndex|dropForeign|truncate|->rename\(|renameColumn|->change\(\)|DB::unprepared|DB::statement\(\s*['"]\s*(drop|delete|truncate|alter)|delete\s+from)/i;
const DANGEROUS_PHP = /\b(shell_exec|exec|system|passthru|proc_open|popen|eval)\s*\(/;

/** @param {{status: string, path: string}[]} files */
export function assessChanges({ worktree, baseCommit, files, maxFilesWithoutApproval = 30 }) {
  const forbidden = [];
  const approvals = [];
  const blockers = [];

  for (const f of files) {
    const rule = FORBIDDEN.find((r) => r.re.test(f.path));
    if (rule) forbidden.push({ path: f.path, why: rule.why });
  }

  const live = files.filter((f) => !forbidden.some((x) => x.path === f.path));

  for (const f of live) {
    const rule = APPROVAL_PATHS.find((r) => r.re.test(f.path));
    if (rule) approvals.push({ rule: rule.why, detail: `${f.status === 'A' ? 'added' : f.status === 'D' ? 'deleted' : 'modified'} ${f.path}` });

    if (f.status === 'D' && !/^tests\//.test(f.path)) {
      approvals.push({ rule: 'file deletion', detail: `deleted ${f.path}` });
    }

    if (f.status === 'D') continue;
    const abs = path.resolve(worktree, f.path);
    let body = '';
    try {
      if (fs.statSync(abs).size <= 512 * 1024) body = fs.readFileSync(abs, 'utf-8');
    } catch {
      continue;
    }

    if (/^database\/migrations\//.test(f.path) && DESTRUCTIVE_SQL.test(body)) {
      approvals.push({ rule: 'destructive database change', detail: `${f.path} contains a drop/truncate/rename/raw-SQL statement` });
    }
    if (/\.php$/.test(f.path) && !/^tests\//.test(f.path) && DANGEROUS_PHP.test(body)) {
      approvals.push({ rule: 'dangerous PHP function', detail: `${f.path} calls exec/eval/system-style functions` });
    }
    if (containsSecret(body)) {
      blockers.push({ path: f.path, why: 'contains a string that looks like a credential' });
    }
  }

  if (live.length > maxFilesWithoutApproval) {
    approvals.push({ rule: 'large change', detail: `${live.length} files changed (more than ${maxFilesWithoutApproval})` });
  }

  return { forbidden, approvals: dedupe(approvals), blockers };
}

function dedupe(list) {
  const seen = new Set();
  return list.filter((a) => {
    const k = `${a.rule}|${a.detail}`;
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
}

/** Does the planned target list overlap another active task's claims? Returns the conflicts. */
export function claimConflicts(task, allTasks) {
  const mine = new Set((task.plan?.targetFiles || []).map(normalize));
  const out = [];
  for (const other of Object.values(allTasks)) {
    if (other.id === task.id || ['passed', 'aborted', 'failed'].includes(other.status)) continue;
    const overlap = (other.plan?.targetFiles || []).map(normalize).filter((p) => mine.has(p));
    if (overlap.length) out.push({ taskId: other.id, files: overlap });
  }
  return out;
}

const normalize = (p) => String(p).replace(/^\.\//, '');

export { git as _git };
