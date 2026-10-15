// Deterministic stand-ins for the three agents. Behaviour is chosen by FLOWSYNC_FAKE_STATE, a path to a
// JSON file { scenario, counters } the self-test writes; counters let a stub act differently per call.
import fs from 'node:fs';
import path from 'node:path';

const input = JSON.parse(fs.readFileSync(0, 'utf-8'));
const cfgPath = process.env.FLOWSYNC_FAKE_STATE;
const cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf-8'));
const bump = (k) => {
  cfg.counters[k] = (cfg.counters[k] || 0) + 1;
  fs.writeFileSync(cfgPath, JSON.stringify(cfg));
  return cfg.counters[k];
};
const S = cfg.scenario;
const out = (o) => process.stdout.write(typeof o === 'string' ? o : JSON.stringify(o));
const write = (rel, body) => {
  const f = path.join(input.cwd, rel);
  fs.mkdirSync(path.dirname(f), { recursive: true });
  fs.writeFileSync(f, body);
};

export function run(role) {
  const n = bump(role);
  cfg.prompts ||= {};
  fs.writeFileSync(cfgPath, JSON.stringify({ ...cfg, prompts: { ...(cfg.prompts || {}), [`${role}-${n}`]: input.prompt.slice(0, 6000) } }));

  if (role === 'plan' || role === 'revise') {
    return out({
      title: 'Add a hello helper',
      requirements: 'Create app/Hello.php returning a greeting and a test for it.',
      targetFiles: S === 'conflict' ? ['app/Shared.php'] : S === 'parallel' ? [`app/P${process.pid}.php`] : ['app/Hello.php', 'tests/Feature/HelloTest.php'],
      tests: ['tests/Feature/HelloTest.php', 'tests/../../etc/passwd'], // the second is hostile and must be dropped
      acceptanceCriteria: ['greet() returns "hello"', 'the test passes'],
      dependencies: [],
      riskNotes: [],
    });
  }

  if (role === 'validate') {
    if (S === 'gaps' && n === 1) return out({ verdict: 'gaps_identified', analysis: 'Missing an unauthenticated-user case.', gaps: ['No test for unauthenticated users'], edgeCases: ['empty name'], recommendations: ['Add an unauthenticated test'] });
    if (S === 'reject') return out({ verdict: 'rejected', analysis: 'This task should not be done.', gaps: [] });
    return out({ verdict: 'approved', analysis: 'Sound plan.', gaps: [], edgeCases: ['empty name'], recommendations: [] });
  }

  if (role === 'implement') {
    const attempt = n;
    if (S === 'crash-implement' && attempt === 1) {
      process.stderr.write('simulated crash');
      process.exit(3);
    }
    const helloPath = S === 'parallel' ? `app/Hello${process.pid}.php` : 'app/Hello.php';
    write(helloPath, '<?php\n\nnamespace App;\n\nclass Hello\n{\n    public static function greet(): string\n    {\n        return \'hello\';\n    }\n}\n');
    write('tests/Feature/HelloTest.php', '<?php\n// test\n');

    if (S === 'correction') write('.fake-test-result', attempt === 1 ? 'fail' : 'pass');
    if (S === 'exhaust') write('.fake-test-result', 'fail');
    if (S === 'approval') write('database/migrations/2026_01_01_000000_drop_old_column.php', "<?php\nSchema::table('x', fn ($t) => $t->dropColumn('old'));\n");
    if (S === 'forbidden' && attempt === 1) {
      write('.env.production', 'APP_KEY=hacked\n');
      write('.agents/orchestrator/evil.json', '{}');
    }
    if (S === 'secret' && attempt === 1) write('app/Leak.php', `<?php\n$key = '${['sk', 'test', 'sampletestdummykey12345678'].join('_')}';\n`);
    if (S === 'secret' && attempt === 2) fs.rmSync(path.join(input.cwd, 'app/Leak.php'), { force: true }); // the implementer acts on the feedback
    if (S === 'parallel') write('.fake-test-result', 'pass');
    if (S === 'interrupt-review' || S === 'happy' || S === 'conflict') write('.fake-test-result', 'pass');
    if (S === 'happy-unique') write('.fake-test-result', 'pass');
    return out(`Implemented attempt ${attempt}.`);
  }

  if (role === 'review') {
    if (S === 'interrupt-review' && n === 1) process.kill(process.ppid, 'SIGKILL'); // the orchestrator dies mid-review
    if (S === 'correction' && n === 1) {
      return out({ verdict: 'changes_requested', summary: 'Tests fail.', issuesFound: [{ severity: 'blocker', file: 'tests/Feature/HelloTest.php', description: 'MARKER-ISSUE-ONE: the test does not assert greet()', fix: 'assert greet() === "hello"' }], suggestions: [] });
    }
    if (S === 'exhaust') return out({ verdict: 'changes_requested', summary: 'Still wrong.', issuesFound: [{ severity: 'major', description: 'Still wrong', fix: 'fix it' }], suggestions: [] });
    // Deliberately naive: approves even when the scan/checks should veto it — the orchestrator must override.
    return out({ verdict: 'approved', summary: 'Looks good.', issuesFound: [], suggestions: [] });
  }
  throw new Error(`unknown role ${role}`);
}
