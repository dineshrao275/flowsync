#!/usr/bin/env python3
"""Move named top-level declarations (function/const) out of a JSX file into a new module.

  scripts/extract-components.py <source.jsx> <dest.jsx> Name1 Name2 ...

Preceding comment lines travel with each declaration. The destination gets only the imports it
uses (relative paths rebased); the source imports the moved names back. Unresolved identifiers
are left for ESLint (no-undef) to report - run it afterwards.
"""
import os
import re
import sys

src_path, dest_path, *names = sys.argv[1:]
text = open(src_path).read()
# Collapse multi-line named imports so every import is one line.
text = re.sub(r"import\s*\{([^}]*)\}\s*from\s*'([^']*)';", lambda m: 'import { ' + ' '.join(m.group(1).split()).rstrip(',') + " } from '" + m.group(2) + "';", text)
lines = text.split('\n')

decl = re.compile(r'^(?:export default |export )?(?:function|const|let) (\w+)')
tops = []  # (index, name)
for i, l in enumerate(lines):
    m = decl.match(l)
    if m:
        tops.append((i, m.group(1)))

def block_range(idx):
    start = tops[idx][0]
    while start > 0 and (lines[start - 1].startswith('//') or lines[start - 1].startswith(' *') or lines[start - 1].startswith('/**') or lines[start - 1].startswith('*/')):
        start -= 1
    end = tops[idx + 1][0] if idx + 1 < len(tops) else len(lines)
    nxt = end
    while nxt > start and (lines[nxt - 1].strip() == '' or lines[nxt - 1].startswith('//') or lines[nxt - 1].startswith(' *') or lines[nxt - 1].startswith('/**') or lines[nxt - 1].startswith('*/')):
        nxt -= 1
    return start, nxt

moved = []
ranges = []
for n in names:
    idx = next(i for i, (_, nm) in enumerate(tops) if nm == n)
    s, e = block_range(idx)
    ranges.append((s, e))
    moved.append('\n'.join(lines[s:e]).rstrip())
body = '\n\n'.join(moved) + '\n'
for n in names:
    body = re.sub(r'^(function|const) ' + n + r'\b', r'export \1 ' + n, body, flags=re.M)

imports = [l for l in lines if l.startswith('import ')]

def idents(imp):
    m = re.match(r"import (.*) from '(.*)';", imp)
    spec = m.group(1)
    return re.findall(r'[A-Za-z_$][\w$]*', spec.replace('{', ' ').replace('}', ' ').replace(',', ' ').replace(' as ', ' '))

def uses(block, imp):
    return any(re.search(r'(?<![\w.])' + re.escape(n) + r'\b', block) for n in idents(imp))

src_dir = os.path.dirname(src_path)
dst_dir = os.path.dirname(dest_path)

def rebase(imp):
    m = re.match(r"(import .* from ')(.*)(';)", imp)
    p = m.group(2)
    if p.startswith('.'):
        absolute = os.path.normpath(os.path.join(src_dir, p))
        p = os.path.relpath(absolute, dst_dir)
        if not p.startswith('.'):
            p = './' + p
    return m.group(1) + p + m.group(3)

dest_imports = [rebase(i) for i in imports if uses(body, i)]
os.makedirs(dst_dir, exist_ok=True)
open(dest_path, 'w').write('\n'.join(dest_imports) + ('\n\n' if dest_imports else '') + body)

# rewrite the source
keep = [True] * len(lines)
for s, e in ranges:
    for k in range(s, e):
        keep[k] = False
remaining = [l for k, l in enumerate(lines) if keep[k]]
rest = '\n'.join(remaining)
rest = re.sub(r'\n{3,}', '\n\n', rest)
rel = os.path.relpath(dest_path[:-4], src_dir)
if not rel.startswith('.'):
    rel = './' + rel
new_import = f"import {{ {', '.join(n for n in names)} }} from '{rel}';"
# only import names the remaining source still uses
used_names = [n for n in names if re.search(r'(?<![\w.])' + n + r'\b', rest)]
new_import = f"import {{ {', '.join(used_names)} }} from '{rel}';"
last_import = max(i for i, l in enumerate(rest.split('\n')) if l.startswith('import '))
parts = rest.split('\n')
parts.insert(last_import + 1, new_import)
rest = '\n'.join(parts)
# drop now-unused imports
out = []
body_only = '\n'.join(l for l in rest.split('\n') if not l.startswith('import '))
for l in rest.split('\n'):
    if l.startswith('import ') and not uses(body_only, l) and 'react' not in l.split(' from ')[0]:
        continue
    out.append(l)
open(src_path, 'w').write('\n'.join(out))

def prune_file(path):
    """Drop unused named specifiers (and wholly unused imports) from a file's import block."""
    ls = open(path).read().split('\n')
    body_text = '\n'.join(l for l in ls if not l.startswith('import '))
    out = []
    for l in ls:
        m = re.match(r"import (?:(\w+)(?:, )?)?(?:\{(.*)\})? from '(.*)';", l)
        if not m:
            out.append(l)
            continue
        default, named, mod = m.group(1), m.group(2), m.group(3)
        def used(n):
            return re.search(r'(?<![\w.])' + re.escape(n) + r'\b', body_text) is not None
        keep_default = default if default and used(default) else None
        keep_named = [n.strip() for n in (named or '').split(',') if n.strip() and used(n.strip().split(' as ')[-1])]
        if not keep_default and not keep_named:
            continue
        spec = ', '.join(x for x in [keep_default, ('{ ' + ', '.join(keep_named) + ' }') if keep_named else None] if x)
        out.append(f"import {spec} from '{mod}';")
    open(path, 'w').write('\n'.join(out))

prune_file(dest_path)
prune_file(src_path)
print(f'moved {len(names)} declaration(s) -> {dest_path}')
