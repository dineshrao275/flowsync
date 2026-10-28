#!/usr/bin/env python3
"""Inventory of every write call the React UI makes, matched to the API routes and to the form audit results.

For each `api.post|put|patch|delete(...)` in resources/js it records the file, the method, the endpoint and whether
the endpoint exists as a route. A UI call that matches no route is a broken form. Each matched call is joined with the
audit outcome for that route (which roles could submit it).

  scripts/form-audit-ui-inventory.py            summary + unmatched calls
  scripts/form-audit-ui-inventory.py --markdown  full table (docs/qa/ui-forms.md)
"""
import collections
import json
import os
import re
import subprocess
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
os.chdir(ROOT)

routes = {}
for r in json.loads(subprocess.run(['php', 'artisan', 'route:list', '--json'], capture_output=True, text=True).stdout):
    for m in r['method'].split('|'):
        if m in ('POST', 'PUT', 'PATCH', 'DELETE') and r['uri'].startswith('api/'):
            routes[(m, r['uri'])] = r


def regex_for(uri):
    parts = []
    for seg in uri.split('/'):
        parts.append('[^/]+' if seg.startswith('{') else re.escape(seg))
    return re.compile('^' + '/'.join(parts) + '$')


compiled = [(m, uri, regex_for(uri)) for (m, uri) in routes]

# audit outcome per route: roles that succeeded
ns = {'__file__': os.path.join(ROOT, 'scripts/form-audit-report.py')}
exec(open(ns['__file__']).read().split("if '--route' in sys.argv")[0], ns)
passed = collections.defaultdict(set)
seen = set()
for r in ns['rows']:
    seen.add(r['route'])
    if r['cls'] == 'PASS':
        passed[r['route']].add(r['perspective'])

calls = []
pat = re.compile(r"\bapi\.(post|put|patch|delete)\(\s*(`[^`]*`|'[^']*'|\"[^\"]*\")", re.S)
for dirpath, _, files in os.walk('resources/js'):
    for f in files:
        if not f.endswith(('.jsx', '.js')) or '.test.' in f:
            continue
        path = os.path.join(dirpath, f)
        text = open(path).read()
        for m in pat.finditer(text):
            raw = m.group(2)[1:-1]
            endpoint = re.sub(r'\$\{[^}]*\}', '{x}', raw)
            endpoint = endpoint.split('?')[0].strip('/')
            line = text[:m.start()].count('\n') + 1
            calls.append({'file': path.replace('resources/js/', ''), 'line': line, 'method': m.group(1).upper(), 'endpoint': endpoint})

rows = []
for c in calls:
    ui = ('api/' + c['endpoint']).split('/')
    match = None
    matches = []
    for method, uri, _ in compiled:
        if method != c['method']:
            continue
        rs = uri.split('/')
        # An optional trailing parameter (`{user?}`) may be left out by the caller.
        variants = [rs] + ([rs[:-1]] if rs[-1].endswith('?}') else [])
        rs_match = next((v for v in variants if len(v) == len(ui)), None)
        if rs_match is None:
            continue
        rs = rs_match
        # A dynamic UI segment (`${action}`) can be any route segment; a route parameter accepts anything.
        if all(r.startswith('{') or u == '{x}' or r == u for r, u in zip(rs, ui)):
            matches.append(uri)
    match = matches[0] if matches else None
    c['routes'] = matches
    c['route'] = match
    keys = [f"{c['method']} {u}" for u in c['routes']]
    c['roles'] = sorted(set().union(*[passed.get(k, set()) for k in keys])) if keys else []
    c['audited'] = any(k in seen for k in keys)
    rows.append(c)

unmatched = [c for c in rows if c['route'] is None]
routes_called = {f"{c['method']} {u}" for c in rows for u in c['routes']}
api_only = sorted(f'{m} {u}' for (m, u) in routes if f'{m} {u}' not in routes_called)

if '--markdown' in sys.argv:
    print('| UI source | Call | Endpoint matched | Roles that submitted it successfully in the audit |')
    print('|---|---|---|---|')
    for c in sorted(rows, key=lambda x: (x['file'], x['line'])):
        roles = ', '.join(c['roles']) if c['roles'] else ('(not exercised successfully)' if c['route'] else '**no matching route**')
        shown = ', '.join('`' + u.split('/', 1)[1] + '`' for u in c['routes'][:3]) + (' …' if len(c['routes']) > 3 else '') if c['routes'] else '-'
        print(f"| `{c['file']}:{c['line']}` | {c['method']} `/{c['endpoint']}` | {shown} | {roles} |")
    sys.exit()

print(f'{len(rows)} UI write calls in {len({c["file"] for c in rows})} files')
print(f'  matched to a route: {len(rows) - len(unmatched)}')
print(f'  with NO matching route: {len(unmatched)}')
for c in unmatched:
    print(f"    {c['file']}:{c['line']}  {c['method']} /{c['endpoint']}")
ok = sum(1 for c in rows if c['roles'])
print(f'  submitted successfully by at least one role in the audit: {ok}')
print(f'API write routes the UI never calls: {len(api_only)}')
