#!/usr/bin/env python3
"""Convert `$x = $request->validate([...])` in controller methods into FormRequest classes.

Safe-by-construction rules (a call that breaks any is left alone):
  - the method takes `Request $request`;
  - the call is the first statement of the method body (only comments before it), so validation
    still runs before anything else, exactly as the FormRequest does;
  - the rules array is one argument (no custom messages), references no variable except
    `$request` (rewritten to `$this`) and imports only classes the controller already imports.
Usage: inline-validation-to-formrequest.py dry|apply [controller files...]
"""
import os
import re
import sys

ROOT = 'app/Http/Controllers'
BLOCKERS = re.compile(r'authorize|Gate::|abort|throw |return |if \(|->can\(|hasPermission|hasRole|OrFail|assertCan|requireTenantAdmin|isPlatformSuperAdmin')


def balanced(s, i):
    depth, quote = 0, None
    j = i
    while j < len(s):
        c = s[j]
        if quote:
            if c == '\\':
                j += 2
                continue
            if c == quote:
                quote = None
        elif c in '\'"':
            quote = c
        elif c in '([':
            depth += 1
        elif c in ')]':
            depth -= 1
            if depth == 0:
                return j + 1
        j += 1
    return -1


def process(path, apply):
    src = open(path).read()
    mc = re.search(r'\nclass (\w+)', src)
    if not mc:
        return []
    ns = re.search(r'namespace ([^;]+);', src).group(1)
    base = mc.group(1).replace('Controller', '')
    sub = ns.replace('App\\Http\\Controllers', '').strip('\\')
    reqns = 'App\\Http\\Requests' + ('\\' + sub if sub else '')
    reqdir = 'app/Http/Requests' + ('/' + sub.replace('\\', '/') if sub else '')

    method_pat = re.compile(r'    public function (\w+)\(([^)]*)\)[^{;]*\{\n')
    methods = list(method_pat.finditer(src))
    edits = []
    used_names = set()
    for k, m in enumerate(methods):
        if 'Request $request' not in m.group(2):
            continue
        body_start = m.end()
        body_end = methods[k + 1].start() if k + 1 < len(methods) else len(src)
        body = src[body_start:body_end]
        vm = re.search(r'\$(\w+) = \$request->validate\(', body)
        if not vm:
            continue
        before = re.sub(r'//[^\n]*|/\*.*?\*/', '', body[:vm.start()], flags=re.S).strip()
        # Anything before the call (a merge, a quota check, a lookup) could change what is validated
        # or which error wins, so only a first-statement call is converted.
        if before:
            continue
        call_open = body_start + vm.end() - 1
        call_end = balanced(src, call_open)
        if call_end < 0:
            continue
        inner = src[call_open + 1:call_end - 1].strip()
        if not inner.startswith('[') or balanced(inner, 0) != len(inner):
            continue
        rules = inner.replace('$request->', '$this->')
        if re.search(r'\$(?!this\b)\w+', rules) or 'self::' in rules or 'static::' in rules or 'function' in rules or 'fn (' in rules or 'new ' in rules:
            continue
        extra = []
        ok = True
        for cn in sorted(set(re.findall(r'\b([A-Z][A-Za-z0-9_]*)::', rules)) - {'Rule', 'File', 'Password'}):
            um = re.search(r'^use ([^;]*\\' + cn + r');', src, re.M)
            if um:
                extra.append(um.group(1))
            elif os.path.exists(os.path.join(ROOT, sub.replace('\\', '/'), cn + '.php')):
                extra.append(ns + '\\' + cn)
            else:
                ok = False
        if not ok:
            continue
        name = f"{base}{m.group(1)[0].upper()}{m.group(1)[1:]}Request"
        if name in used_names or os.path.exists(f'{reqdir}/{name}.php'):
            continue
        used_names.add(name)
        uses = sorted(set(re.findall(r'\b(Rule|File|Password)::', rules)))
        use_lines = ''.join(f'use {e};\n' for e in extra)
        for u in uses:
            use_lines += 'use Illuminate\\Validation\\Rules\\File;\n' if u == 'File' else f'use Illuminate\\Validation\\{u};\n'
        php = f"""<?php

declare(strict_types=1);

namespace {reqns};

use Illuminate\\Foundation\\Http\\FormRequest;
{use_lines}
class {name} extends FormRequest
{{
    public function authorize(): bool
    {{
        return true;
    }}

    /** @return array<string, mixed> */
    public function rules(): array
    {{
        return {rules};
    }}
}}
"""
        semi = src.index(';', call_end)
        edits.append((m, name, vm.group(1), call_open, semi, php))
    if not edits:
        return []
    new = src
    for m, name, var, call_open, semi, php in sorted(edits, key=lambda e: -e[3]):
        stmt_start = new.rindex(f'${var} = $request->validate(', 0, call_open + 1)
        new = new[:stmt_start] + f'${var} = $request->validated();' + new[semi + 1:]
        head = m.group(0)
        new = new.replace(head, head.replace('Request $request', f'{name} $request', 1), 1)
    imports = ''.join(f'use {reqns}\\{e[1]};\n' for e in edits)
    new = re.sub(r'(namespace [^;]+;\n\n(?:declare[^\n]*\n\n)?)', lambda mm: mm.group(1) + imports, new, count=1)
    if apply:
        os.makedirs(reqdir, exist_ok=True)
        for e in edits:
            open(f'{reqdir}/{e[1]}.php', 'w').write(e[5])
        open(path, 'w').write(new)
    return [e[1] for e in edits]


if __name__ == '__main__':
    mode = sys.argv[1]
    files = sys.argv[2:] or [os.path.join(d, f) for d, _, fs in os.walk(ROOT) for f in fs if f.endswith('.php')]
    total = 0
    for f in sorted(files):
        made = process(f, mode == 'apply')
        if made:
            print(f, len(made))
            total += len(made)
    print('total', total)
