#!/usr/bin/env python3
"""Summarise storage/app/form-audit/results.jsonl (written by tests/Audit/FormAuditTest.php).

  scripts/form-audit-report.py            overview + the lists that need a human decision
  scripts/form-audit-report.py --route 'POST api/workspaces'   every perspective for one route
  scripts/form-audit-report.py --class SERVER_ERROR             every row of one class

Classes: PASS, EXPECTED_DENY (the role lacks the route's permission), POLICY_DENY (has the permission, refused by a
policy/module/product/record rule - needs review), AUTH_OK (guest correctly refused), NO_FIXTURE (record missing),
HIDDEN_404 (404 although a record was resolved), UNRESOLVED_422, BUSINESS_STATE (402/409/429...), SERVER_ERROR,
UNEXPECTED_ALLOW, UNEXPECTED_401, HARNESS, SKIPPED.
"""
import collections
import json
import os
import sys

PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'storage/app/form-audit/results.jsonl')
rows = [json.loads(line) for line in open(PATH)]


def classify(r):
    if r.get('class') in ('SKIPPED', 'NO_PENDING_APPROVAL'):
        return r['class']
    if r.get('harness_error'):
        return 'HARNESS'
    s = r.get('status', 0)
    guest = r['perspective'] == 'guest'
    workflow = r['perspective'] in ('approver', 'requester')
    if 200 <= s < 400:
        if workflow:
            return 'PASS'
        if guest and r.get('gate_ok') is not None:
            return 'UNEXPECTED_ALLOW'
        if r.get('gate_ok') is False and not guest and r['perspective'] != 'super_admin':
            return 'UNEXPECTED_ALLOW'
        return 'PASS'
    if s == 401:
        return 'AUTH_OK' if guest else 'UNEXPECTED_401'
    if s == 403:
        return 'EXPECTED_DENY' if r.get('gate_ok') is False or guest else 'POLICY_DENY'
    if s == 404:
        return 'NO_FIXTURE' if r.get('fixture_notes') else 'HIDDEN_404'
    if s == 422:
        return 'UNRESOLVED_422'
    if s >= 500:
        return 'SERVER_ERROR'
    return 'BUSINESS_STATE'


for r in rows:
    r['cls'] = classify(r)

if '--route' in sys.argv:
    wanted = sys.argv[sys.argv.index('--route') + 1]
    for r in rows:
        if r['route'] == wanted:
            print(f"{r['perspective']:<16} {r.get('status')} {r['cls']:<15} att={r.get('attempts')} {(r.get('message') or r.get('harness_error') or '')[:90]}")
            if r.get('errors'):
                print('    errors:', json.dumps(r['errors'])[:260])
            if r.get('payload'):
                print('    payload:', r['payload'][:260])
    sys.exit()

if '--class' in sys.argv:
    wanted = sys.argv[sys.argv.index('--class') + 1]
    for r in rows:
        if r['cls'] == wanted:
            print(f"{r['route']:<62} {r['perspective']:<15} {r.get('status')} {(r.get('exception') or r.get('message') or r.get('harness_error') or '')[:150]}")
    sys.exit()

if '--matrix' in sys.argv:
    # One line per form: what each role got. Writes markdown to stdout.
    order = ['guest', 'super_admin', 'admin', 'editor', 'project_lead', 'viewer', 'manager', 'hr_manager', 'payroll_manager', 'employee', 'hr_executive', 'finance_admin', 'auditor', 'approver', 'requester']
    short = {'guest': 'guest', 'super_admin': 'SA', 'admin': 'admin', 'editor': 'editor', 'project_lead': 'lead', 'viewer': 'viewer', 'manager': 'mgr', 'hr_manager': 'hrm', 'payroll_manager': 'pay', 'employee': 'emp', 'hr_executive': 'hrx', 'finance_admin': 'fin', 'auditor': 'aud', 'approver': 'appr', 'requester': 'req'}
    sym = {'PASS': 'OK', 'EXPECTED_DENY': 'no', 'POLICY_DENY': 'pol', 'AUTH_OK': 'no', 'NO_FIXTURE': 'nf', 'HIDDEN_404': '404', 'UNRESOLVED_422': '422', 'SERVER_ERROR': '500', 'BUSINESS_STATE': 'biz', 'UNEXPECTED_ALLOW': '!!', 'UNEXPECTED_401': '401', 'HARNESS': 'err', 'SKIPPED': '-', 'NO_PENDING_APPROVAL': 'n/a'}
    best = {}
    for r in rows:
        if str(r.get('phase', '')).startswith('bootstrap') and r['cls'] != 'PASS':
            continue
        key = (r['route'], r['perspective'])
        # keep the best outcome per (route, perspective): a success beats a refusal
        rank = {'PASS': 0, 'SERVER_ERROR': 1}.get(r['cls'], 2)
        if key not in best or rank < best[key][0]:
            best[key] = (rank, r['cls'])
    routes = sorted({r['route'] for r in rows}, key=lambda x: (x.split(' ', 1)[1], x))
    print('| Form (method + endpoint) | ' + ' | '.join(short[o] for o in order) + ' |')
    print('|---|' + '---|' * len(order))
    for route in routes:
        cells = []
        for o in order:
            v = best.get((route, o))
            cells.append(sym[v[1]] if v else '')
        print(f'| `{route}` | ' + ' | '.join(cells) + ' |')
    sys.exit()

by_cls = collections.Counter(r['cls'] for r in rows)
total = sum(by_cls.values())
print(f'{total} rows, {len({r["route"] for r in rows})} routes')
for k, v in by_cls.most_common():
    print(f'  {k:<18}{v:>6}')

# Routes that never succeeded for any perspective: unverified forms.
per_route = collections.defaultdict(list)
for r in rows:
    per_route[r['route']].append(r)
never = {k: v for k, v in per_route.items() if not any(x['cls'] == 'PASS' for x in v) and not all(x['cls'] == 'SKIPPED' for x in v)}
print(f'\nroutes with NO successful submission by any perspective: {len(never)} of {len(per_route)}')
reason = collections.Counter()
for k, v in never.items():
    classes = {x['cls'] for x in v if x['perspective'] != 'guest'}
    reason['/'.join(sorted(classes))] += 1
for k, v in reason.most_common():
    print(f'  {v:>4}  {k}')
