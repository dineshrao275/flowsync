#!/usr/bin/env python3
"""For every write route the audit could not submit successfully, find the automated tests that already submit it.

A route nobody can verify through the sweep (it needs a record in one exact state, a specific person, an external
service) is acceptable when a test builds that state and asserts success; one with neither is a real gap.
  scripts/form-audit-coverage.py            summary + the routes with no evidence at all
  scripts/form-audit-coverage.py --all      every unverified route with its evidence
"""
import collections
import glob
import json
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
ns = {'__file__': os.path.join(ROOT, 'scripts/form-audit-report.py')}
exec(open(ns['__file__']).read().split("if '--route' in sys.argv")[0], ns)
rows = ns['rows']

per = collections.defaultdict(list)
for r in rows:
    per[r['route']].append(r)
unverified = sorted(k for k, v in per.items() if not any(x['cls'] == 'PASS' for x in v) and not all(x['cls'] == 'SKIPPED' for x in v))

tests = {f: open(f, errors='ignore').read() for f in glob.glob(os.path.join(ROOT, 'tests/Feature/**/*.php'), recursive=True)}


def pattern(route):
    method, uri = route.split(' ', 1)
    parts = []
    for seg in uri.split('/'):
        parts.append(r'[^/\'"\s]+' if seg.startswith('{') else re.escape(seg))
    return method, re.compile('/' + '/'.join(parts) + r'(?![\w/{-])' if False else '/' + '/'.join(parts) + r'(?=[\'"?\s,)]|$)')


evidence = {}
for route in unverified:
    method, rx = pattern(route)
    verb = {'POST': 'post', 'PUT': 'put', 'PATCH': 'patch', 'DELETE': 'delete'}[method]
    hits = []
    for f, text in tests.items():
        for m in re.finditer(r'(?:->|\b)(?:' + verb + r'Json|json\(\s*[\'"]' + method + r'[\'"]\s*,|call\(\s*[\'"]' + method + r'[\'"]\s*,)\s*\(?\s*[\'"]([^\'"]+)[\'"]', text):
            candidate = re.sub(r'\{\$[^}]+\}|\$\w+', 'X', m.group(1))
            candidate = re.sub(r'\?.*$', '', candidate)
            if rx.fullmatch(candidate) or rx.fullmatch(re.sub(r'/\d+', '/X', candidate)):
                hits.append(os.path.basename(f))
                break
    evidence[route] = hits

covered = {k: v for k, v in evidence.items() if v}
none = [k for k, v in evidence.items() if not v]
print(f'{len(unverified)} routes without a successful sweep submission')
print(f'  with an automated test that submits them: {len(covered)}')
print(f'  with NO evidence at all (sweep or test): {len(none)}')
if '--all' in sys.argv:
    for k in unverified:
        print(f"  {k:<64} {', '.join(sorted(set(evidence[k]))[:3]) or '-- none --'}")
else:
    for k in none:
        print('  ', k)
