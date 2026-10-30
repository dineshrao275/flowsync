import { Fragment, memo } from 'react';
import TaskBadge from './TaskBadge';
import UserTooltip from './UserTooltip';

/**
 * Renders inline rich text: code, markdown links, URLs, task badges, mentions,
 * bold, italic, strikethrough, and underline.
 */
function renderInline(text, projectId, depth = 0) {
    if (!text && text !== 0) return null;
    const str = String(text);

    // Regex matching:
    // 1: inline code `code`
    // 2: markdown link [title](url) (3: title, 4: url)
    // 5: raw url https://...
    // 6: task key TASK-123
    // 7: mention @Name
    // 8: bold **text** or __text__ (9: text1, 10: text2)
    // 11: italic *text* or _text_ (12: text1, 13: text2)
    // 14: strike ~~text~~ (15: text)
    // 16: underline <u>text</u> (17: text)
    const tokenRegex = /(`[^`\n]+`)|(\[([^\]]+)\]\((https?:\/\/[^\s)]+)\))|(https?:\/\/[^\s<]+)|(\b[A-Z]{2,10}-\d+\b)|(@[A-Za-z0-9_.-]+(?:\s[A-Za-z0-9_.-]+)?)|(\*\*([^*\n]+)\*\*|__([^_]+)__)|(\*([^*\n]+)\*|_([^_\n]+)_)|(~~([^~\n]+)~~)|(<u>([\s\S]*?)<\/u>)/g;

    const elements = [];
    let lastIndex = 0;
    let match;
    let keyIdx = 0;

    while ((match = tokenRegex.exec(str)) !== null) {
        const matchStart = match.index;
        const matchText = match[0];

        if (matchStart > lastIndex) {
            elements.push(
                <Fragment key={`txt-${depth}-${keyIdx++}`}>
                    {str.slice(lastIndex, matchStart)}
                </Fragment>
            );
        }

        // 1: inline code
        if (match[1]) {
            elements.push(
                <code key={`code-${depth}-${keyIdx++}`} className="rounded bg-gray-100 px-1 py-0.5 font-mono text-xs text-pink-600">
                    {match[1].slice(1, -1)}
                </code>
            );
        }
        // 2: markdown link [title](url)
        else if (match[2]) {
            elements.push(
                <a
                    key={`mdlink-${depth}-${keyIdx++}`}
                    href={match[4]}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={(e) => e.stopPropagation()}
                    className="font-medium text-[var(--accent-soft-text)] underline hover:text-[var(--accent)]"
                >
                    {match[3]}
                </a>
            );
        }
        // 5: raw url
        else if (match[5]) {
            elements.push(
                <a
                    key={`url-${depth}-${keyIdx++}`}
                    href={match[5]}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={(e) => e.stopPropagation()}
                    className="text-[var(--accent-soft-text)] underline hover:text-[var(--accent)]"
                >
                    {match[5]}
                </a>
            );
        }
        // 6: task key
        else if (match[6]) {
            elements.push(
                <TaskBadge
                    key={`task-${depth}-${keyIdx++}`}
                    taskKey={match[6]}
                    projectId={projectId}
                />
            );
        }
        // 7: mention
        else if (match[7]) {
            const rawName = match[7].slice(1).trim();
            elements.push(
                <UserTooltip key={`mention-${depth}-${keyIdx++}`} name={rawName}>
                    <span className="inline-flex items-center gap-0.5 rounded bg-[var(--accent-soft)] px-1.5 py-0.5 font-medium text-[var(--accent-soft-text)] transition-colors hover:bg-[var(--accent)] hover:text-white cursor-pointer">
                        <span className="text-[0.7rem] font-bold opacity-75">@</span>
                        <span>{rawName}</span>
                    </span>
                </UserTooltip>
            );
        }
        // 8: bold
        else if (match[8]) {
            const inner = match[9] || match[10];
            elements.push(
                <strong key={`bold-${depth}-${keyIdx++}`} className="font-semibold text-gray-900">
                    {depth < 2 ? renderInline(inner, projectId, depth + 1) : inner}
                </strong>
            );
        }
        // 11: italic
        else if (match[11]) {
            const inner = match[12] || match[13];
            elements.push(
                <em key={`italic-${depth}-${keyIdx++}`} className="italic">
                    {depth < 2 ? renderInline(inner, projectId, depth + 1) : inner}
                </em>
            );
        }
        // 14: strikethrough
        else if (match[14]) {
            const inner = match[15];
            elements.push(
                <del key={`strike-${depth}-${keyIdx++}`} className="line-through text-gray-500">
                    {depth < 2 ? renderInline(inner, projectId, depth + 1) : inner}
                </del>
            );
        }
        // 16: underline
        else if (match[16]) {
            const inner = match[17];
            elements.push(
                <span key={`underline-${depth}-${keyIdx++}`} className="underline">
                    {depth < 2 ? renderInline(inner, projectId, depth + 1) : inner}
                </span>
            );
        }

        lastIndex = matchStart + matchText.length;
    }

    if (lastIndex < str.length) {
        elements.push(
            <Fragment key={`txt-end-${depth}`}>
                {str.slice(lastIndex)}
            </Fragment>
        );
    }

    return elements;
}

/**
 * Splits text into markdown blocks: code blocks, blockquotes, headings, lists,
 * checklists, and paragraphs.
 */
function parseBlocks(text) {
    const lines = text.split(/\r?\n/);
    const blocks = [];
    let inCode = false;
    let codeContent = [];
    let listType = null;
    let listItems = [];

    function flushList() {
        if (listType) {
            blocks.push({ type: listType, items: listItems });
            listType = null;
            listItems = [];
        }
    }

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (line.trim().startsWith('```')) {
            flushList();
            if (inCode) {
                blocks.push({ type: 'codeblock', content: codeContent.join('\n') });
                codeContent = [];
                inCode = false;
            } else {
                inCode = true;
            }
            continue;
        }

        if (inCode) {
            codeContent.push(line);
            continue;
        }

        if (line.startsWith('### ')) {
            flushList();
            blocks.push({ type: 'h3', content: line.slice(4) });
            continue;
        }
        if (line.startsWith('## ')) {
            flushList();
            blocks.push({ type: 'h2', content: line.slice(3) });
            continue;
        }
        if (line.startsWith('# ')) {
            flushList();
            blocks.push({ type: 'h1', content: line.slice(2) });
            continue;
        }
        if (line.startsWith('> ')) {
            flushList();
            blocks.push({ type: 'quote', content: line.slice(2) });
            continue;
        }

        const taskMatch = line.match(/^[-*]\s+\[([ xX])\]\s+(.*)$/);
        if (taskMatch) {
            if (listType !== 'checklist') flushList();
            listType = 'checklist';
            listItems.push({ checked: taskMatch[1].toLowerCase() === 'x', content: taskMatch[2] });
            continue;
        }

        const bulletMatch = line.match(/^[-*]\s+(.*)$/);
        if (bulletMatch) {
            if (listType !== 'bullet') flushList();
            listType = 'bullet';
            listItems.push({ content: bulletMatch[1] });
            continue;
        }

        const numMatch = line.match(/^\d+\.\s+(.*)$/);
        if (numMatch) {
            if (listType !== 'number') flushList();
            listType = 'number';
            listItems.push({ content: numMatch[1] });
            continue;
        }

        flushList();
        if (line.trim() === '') {
            blocks.push({ type: 'blank' });
        } else {
            blocks.push({ type: 'p', content: line });
        }
    }
    flushList();
    if (inCode) {
        blocks.push({ type: 'codeblock', content: codeContent.join('\n') });
    }
    return blocks;
}

/**
 * Parses text and highlights rich content:
 * 1. Mentions (@username or @Full Name) -> styled mention badge with UserTooltip
 * 2. Task Keys (e.g. TASK-123, PROJ-45) -> clickable TaskBadge
 * 3. URLs & Markdown Links -> clickable links
 * 4. Rich formatting: bold, italic, strike, underline, code, lists, checklists, quotes, headings
 */
function HighlightedContent({ content = '', projectId = null, className = '' }) {
    if (!content && content !== 0) return null;
    const text = String(content);

    // If single line or simple text without block markdown, render inline directly
    const hasBlocks = /^(?:```|#{1,3}\s|>|\s*[-*]\s+(?:\[[ xX]\]|\S)|\s*\d+\.\s+)/m.test(text) || text.includes('\n');

    if (!hasBlocks) {
        return (
            <span className={`whitespace-pre-wrap break-words ${className}`}>
                {renderInline(text, projectId)}
            </span>
        );
    }

    const blocks = parseBlocks(text);

    return (
        <div className={`space-y-1.5 break-words text-sm text-gray-800 ${className}`}>
            {blocks.map((block, idx) => {
                if (block.type === 'blank') {
                    return <div key={`b-${idx}`} className="h-1.5" />;
                }
                if (block.type === 'codeblock') {
                    return (
                        <pre key={`b-${idx}`} className="my-1.5 overflow-x-auto rounded-lg bg-gray-900 p-2.5 font-mono text-xs text-gray-100 shadow-inner">
                            <code>{block.content}</code>
                        </pre>
                    );
                }
                if (block.type === 'h1') {
                    return (
                        <h1 key={`b-${idx}`} className="my-1 text-base font-bold text-gray-900">
                            {renderInline(block.content, projectId)}
                        </h1>
                    );
                }
                if (block.type === 'h2') {
                    return (
                        <h2 key={`b-${idx}`} className="my-1 text-sm font-bold text-gray-900">
                            {renderInline(block.content, projectId)}
                        </h2>
                    );
                }
                if (block.type === 'h3') {
                    return (
                        <h3 key={`b-${idx}`} className="my-0.5 text-xs font-bold uppercase tracking-wider text-gray-700">
                            {renderInline(block.content, projectId)}
                        </h3>
                    );
                }
                if (block.type === 'quote') {
                    return (
                        <blockquote key={`b-${idx}`} className="my-1 border-l-3 border-[var(--accent)] bg-gray-50/70 py-1 pl-3 pr-2 italic text-gray-700 rounded-r">
                            {renderInline(block.content, projectId)}
                        </blockquote>
                    );
                }
                if (block.type === 'checklist') {
                    return (
                        <ul key={`b-${idx}`} className="my-1 space-y-1 pl-1">
                            {block.items.map((item, itemIdx) => (
                                <li key={`chk-${itemIdx}`} className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={item.checked}
                                        readOnly
                                        className="mt-0.5 h-3.5 w-3.5 rounded border-gray-300 text-[var(--accent)] accent-[var(--accent)] pointer-events-none"
                                    />
                                    <span className={item.checked ? 'line-through text-gray-400' : 'text-gray-800'}>
                                        {renderInline(item.content, projectId)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    );
                }
                if (block.type === 'bullet') {
                    return (
                        <ul key={`b-${idx}`} className="my-1 list-disc list-inside space-y-0.5 pl-2 text-sm text-gray-800">
                            {block.items.map((item, itemIdx) => (
                                <li key={`li-${itemIdx}`}>
                                    {renderInline(item.content, projectId)}
                                </li>
                            ))}
                        </ul>
                    );
                }
                if (block.type === 'number') {
                    return (
                        <ol key={`b-${idx}`} className="my-1 list-decimal list-inside space-y-0.5 pl-2 text-sm text-gray-800">
                            {block.items.map((item, itemIdx) => (
                                <li key={`oli-${itemIdx}`}>
                                    {renderInline(item.content, projectId)}
                                </li>
                            ))}
                        </ol>
                    );
                }
                return (
                    <p key={`b-${idx}`} className="my-0.5">
                        {renderInline(block.content, projectId)}
                    </p>
                );
            })}
        </div>
    );
}

export default memo(HighlightedContent);
