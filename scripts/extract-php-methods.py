#!/usr/bin/env python3
"""Move named methods from a PHP class into a new collaborator class.

  scripts/extract-php-methods.py <source.php> <NewClassShortName> <entry:Method1,Method2,...> [Method3 ...]

Moves every listed method (with its docblock) into <NewClassShortName> in the same namespace and
folder, keeps them private, and adds one public `<entry>()` that calls the comma-separated list in
order. Imports the new class needs are copied by usage. The caller wires the new class into the
source (this tool prints the line to paste) because that is where judgement lives.
"""
import os
import re
import sys

src_path, new_name, entry_spec, *extra = sys.argv[1:]
entry, entry_methods = entry_spec.split(':')
entry_methods = entry_methods.split(',')
names = entry_methods + extra
text = open(src_path).read()

m_ns = re.search(r'namespace ([^;]+);', text)
ns = m_ns.group(1)

method_re = re.compile(r'(?P<doc>(?:    /\*\*.*?\*/\n)?)    (?P<vis>public|private|protected) function (?P<name>\w+)\(', re.S)
starts = [(m.start(), m.group('name')) for m in method_re.finditer(text)]


def block(name):
    idx = next(i for i, (_, n) in enumerate(starts) if n == name)
    s = starts[idx][0]
    e = starts[idx + 1][0] if idx + 1 < len(starts) else text.rindex('}')
    return s, e


moved, spans = [], []
for n in names:
    s, e = block(n)
    spans.append((s, e))
    moved.append(text[s:e].rstrip())
body = '\n\n'.join(moved)
body = re.sub(r'    public function (' + '|'.join(names) + r')\(', r'    private function \1(', body)

imports = [l for l in text.split('\n') if l.startswith('use ') and l.endswith(';')]


def used(imp):
    short = imp.rstrip(';').split('\\')[-1].split(' as ')[-1]
    return re.search(r'(?<![\w\\])' + re.escape(short) + r'\b', body) is not None


kept_imports = [i for i in imports if used(i)]
calls = '\n'.join(f'        $this->{n}();' for n in entry_methods)
cls = f"""<?php

declare(strict_types=1);

namespace {ns};

{chr(10).join(kept_imports)}

/**
 * Extracted from {os.path.basename(src_path)[:-4]} unchanged; see that class for the insert-only and
 * guard-each-step rules these methods follow.
 */
class {new_name}
{{
    public function {entry}(): void
    {{
{calls}
    }}

{body}
}}
"""
out_path = os.path.join(os.path.dirname(src_path), new_name + '.php')
open(out_path, 'w').write(cls)

new_text = text
for s, e in sorted(spans, reverse=True):
    new_text = new_text[:s] + new_text[e:]
new_text = re.sub(r'\n{3,}', '\n\n', new_text)
open(src_path, 'w').write(new_text)
print(f'moved {len(names)} methods -> {out_path}')
print(f'wire in: app({new_name}::class)->{entry}();')
