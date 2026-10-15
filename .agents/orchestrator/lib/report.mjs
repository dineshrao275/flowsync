import fs from 'node:fs';
import path from 'node:path';
import { REPORTS_DIR } from './paths.mjs';
import { redact, redactDeep } from './redact.mjs';

const w = (file, content) => {
  fs.mkdirSync(REPORTS_DIR, { recursive: true });
  fs.writeFileSync(path.join(REPORTS_DIR, file), redact(content), 'utf-8');
};

const list = (items, fmt = (x) => x) => (items && items.length ? items.map((i) => `- ${fmt(i)}`).join('\n') : '- none');
const issueLine = (i) => (typeof i === 'string' ? i : `**${i.severity || 'issue'}**${i.file ? ` \`${i.file}\`` : ''}: ${i.description}${i.fix ? ` — _fix:_ ${i.fix}` : ''}`);

/** The structured review report that goes back to the implementer (also written to disk for humans). */
export function writeReviewReport(task, attemptNo, review, extra = {}) {
  w(`${task.id}-attempt-${attemptNo}-review.json`, JSON.stringify(redactDeep({ taskId: task.id, attempt: attemptNo, review, ...extra }), null, 2));
  w(
    `${task.id}-attempt-${attemptNo}-review.md`,
    [
      `# Review report — \`${task.id}\`, attempt ${attemptNo}`,
      '',
      `**Verdict:** \`${review.verdict}\``,
      '',
      review.summary,
      '',
      '## Issues to fix',
      list(review.issuesFound, issueLine),
      '',
      '## Suggestions',
      list(review.suggestions),
      ...(extra.forbidden?.length ? ['', '## Forbidden changes (reverted)', list(extra.forbidden, (f) => `\`${f.path}\` — ${f.why}`)] : []),
      ...(extra.blockers?.length ? ['', '## Blocking findings', list(extra.blockers, (b) => `\`${b.path}\` — ${b.why}`)] : []),
      '',
    ].join('\n'),
  );
}

export function writeTestOutput(task, attemptNo, checks) {
  const body = (checks.runs || []).map((r) => `$ ${r.command}\n[exit ${r.exitCode}${r.timedOut ? ', TIMED OUT' : ''}] ${r.summary || ''}\n${r.output || ''}`).join('\n\n----\n\n');
  w(`${task.id}-attempt-${attemptNo}-tests.txt`, `${checks.summary}\n\n${body}\n`);
}

export function writeTaskReport(task) {
  const plan = task.plan;
  const v = task.validation;
  const out = [
    `# Agentic workflow report — \`${task.id}\``,
    '',
    `| | |`,
    `|---|---|`,
    `| Status | **${task.status}** |`,
    `| Title | ${task.title} |`,
    `| Branch | ${task.branch ? `\`${task.branch}\`` : '-'} |`,
    `| Worktree | ${task.worktree ? `\`${task.worktree}\`` : '-'} |`,
    `| Base commit | ${task.baseCommit ? `\`${task.baseCommit.slice(0, 12)}\`` : '-'} |`,
    `| Result commit | ${task.commit ? `\`${task.commit.slice(0, 12)}\`` : '-'} |`,
    `| Attempts | ${task.attempts.length} of ${task.maxRetries + 1} allowed |`,
    `| Updated | ${task.updatedAt} |`,
    '',
    '## Goal',
    task.goal,
    '',
    '## 1. Plan (Claude Code)',
    plan ? `**${plan.title}**\n\n${plan.requirements}\n\nFiles:\n${list(plan.targetFiles, (f) => `\`${f}\``)}\n\nTests:\n${list(plan.tests, (t) => `\`${t}\``)}\n\nAcceptance criteria:\n${list(plan.acceptanceCriteria)}\n\nRisk notes:\n${list(plan.riskNotes)}` : '_not planned yet_',
    '',
    '## 2. Independent validation (Gemini CLI)',
    v ? `Verdict: \`${v.verdict}\`\n\n${v.analysis}\n\nGaps:\n${list(v.gaps)}\n\nEdge cases:\n${list(v.edgeCases)}\n\nRecommendations:\n${list(v.recommendations)}` : '_not validated yet_',
    '',
    ...task.attempts.flatMap((a) => [
      `## Attempt ${a.n}`,
      `Started ${a.startedAt}${a.endedAt ? `, ended ${a.endedAt}` : ''}`,
      '',
      '**Implementation (OpenCode):** ' + (a.implementation ? (a.implementation.error ? `error — ${a.implementation.error}` : `done (${a.implementation.changedFiles?.length ?? 0} files changed)`) : '_pending_'),
      '',
      '**Sandboxed checks (Docker):** ' + (a.checks ? `${a.checks.passed ? 'PASSED' : 'FAILED'}\n\n\`\`\`\n${a.checks.summary}\n\`\`\`\nFull output: \`reports/${task.id}-attempt-${a.n}-tests.txt\`` : '_not run_'),
      '',
      '**Risk scan:** ' + (a.risk ? `forbidden: ${a.risk.forbidden.length}, blockers: ${a.risk.blockers.length}, approvals needed: ${a.risk.approvals.length}` : '_not run_'),
      '',
      '**Review (Claude Code):** ' + (a.review ? `\`${a.review.verdict}\` — ${a.review.summary}\n\n${list(a.review.issuesFound, issueLine)}\n\nFull report: \`reports/${task.id}-attempt-${a.n}-review.md\`` : '_pending_'),
      '',
    ]),
    ...(task.approval ? ['## Human approval', `State: **${task.approval.state}**${task.approval.by ? ` by ${task.approval.by}` : ''}`, '', 'Reasons:', list(task.approval.reasons?.map((r) => `${r.rule}: ${r.detail}`)), ''] : []),
    ...(task.lastError ? ['## Last error', '```', task.lastError, '```', ''] : []),
    '## History',
    task.history.map((h) => `- \`${h.ts}\` **${h.status}** (${h.actor}) — ${h.note}`).join('\n'),
    '',
  ];
  w(`${task.id}-report.md`, out.join('\n'));
}
