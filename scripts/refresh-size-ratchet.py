#!/usr/bin/env python3
"""Regenerate the FROZEN map in tests/Unit/ClassSizeCeilingTest.php from current file sizes.
Run after splitting a class so the ratchet tightens: scripts/refresh-size-ratchet.py"""
import os
import sys

os.chdir(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
over = []
for root in ('app', 'routes'):
    for d, _, fs in os.walk(root):
        for f in fs:
            if f.endswith('.php'):
                p = os.path.join(d, f)
                n = sum(1 for _ in open(p, encoding='utf-8', errors='ignore'))
                if n > 300:
                    over.append((p, n))
over.sort()
path = 'tests/Unit/ClassSizeCeilingTest.php'
s = open(path).read()
a = s.index('FROZEN = [\n') + len('FROZEN = [\n')
b = s.index('    ];', a)
open(path, 'w').write(s[:a] + ''.join(f"        '{k}' => {v},\n" for k, v in over) + s[b:])
print(f'{len(over)} files over 300 lines', file=sys.stderr)
