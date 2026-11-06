/**
 * Enter-key list continuation for Markdown text in a plain textarea (the comment editor).
 *
 *   "1. one|"   -> "1. one\n2. |"       numbering carries on, and later items renumber
 *   "- one|"    -> "- one\n- |"         bullets and "- [ ]" checklists continue too
 *   "2. |"      -> "|"                  Enter on an EMPTY item leaves the list
 *
 * Returns `{ value, caret }`, or `null` when the caret is not on a list item (the browser's normal Enter
 * then applies). Indentation is kept, and nothing is done for a selection or a caret inside the marker.
 */
const NUMBERED = /^(\s*)(\d{1,9})\.\s(.*)$/;
const BULLET = /^(\s*)([-*+])\s(\[[ xX]\]\s)?(.*)$/;

function parse(line) {
    let m = NUMBERED.exec(line);
    if (m) return { indent: m[1], kind: 'number', n: Number(m[2]), marker: `${m[1]}${m[2]}. `, content: m[3] };
    m = BULLET.exec(line);
    if (m) {
        const check = m[3] ? '- [ ] ' : null;
        return { indent: m[1], kind: m[3] ? 'task' : 'bullet', bullet: m[2], marker: `${m[1]}${m[2]} ${m[3] ?? ''}`, next: check, content: m[4] };
    }

    return null;
}

function nextMarker(item) {
    if (item.kind === 'number') return `${item.indent}${item.n + 1}. `;
    if (item.kind === 'task') return `${item.indent}${item.bullet} [ ] `;

    return `${item.indent}${item.bullet} `;
}

/** Renumbers the run of numbered items that follows, at the same indent, so an inserted item does not duplicate numbers. */
function renumber(lines, from, indent, start) {
    let n = start;
    for (let i = from; i < lines.length; i += 1) {
        const m = NUMBERED.exec(lines[i]);
        if (!m || m[1] !== indent) break;
        n += 1;
        lines[i] = `${indent}${n}. ${m[3]}`;
    }
}

export function continueList(value, selectionStart, selectionEnd) {
    if (selectionStart !== selectionEnd) return null;

    const text = value ?? '';
    const lineStart = text.lastIndexOf('\n', selectionStart - 1) + 1;
    const found = text.indexOf('\n', selectionStart);
    const lineEnd = found === -1 ? text.length : found;
    const item = parse(text.slice(lineStart, lineEnd));
    if (!item) return null;

    const markerEnd = lineStart + item.marker.length;
    if (selectionStart < markerEnd) return null;

    if (item.content.trim() === '') {
        // Enter on an empty item ends the list: the marker goes, the caret stays on the now-blank line.
        return { value: text.slice(0, lineStart) + text.slice(lineEnd), caret: lineStart };
    }

    const marker = nextMarker(item);
    const before = text.slice(0, selectionStart);
    const after = text.slice(selectionStart);
    const caret = selectionStart + 1 + marker.length;

    if (item.kind !== 'number') return { value: `${before}\n${marker}${after}`, caret };

    const tail = after.split('\n');
    const lines = [tail[0], ...tail.slice(1)];
    renumber(lines, 1, item.indent, item.n + 1);

    return { value: `${before}\n${marker}${lines.join('\n')}`, caret };
}
