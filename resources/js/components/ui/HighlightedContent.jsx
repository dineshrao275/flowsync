import { Fragment, memo } from 'react';
import TaskBadge from './TaskBadge';
import UserTooltip from './UserTooltip';

/**
 * Parses raw text and highlights:
 * 1. Mentions (@username or @Full Name) -> styled mention badge with UserTooltip
 * 2. Task Keys (e.g. TASK-123, PROJ-45) -> clickable TaskBadge
 * 3. URLs -> clickable external link
 */
function HighlightedContent({ content = '', projectId = null, className = '' }) {
    if (!content && content !== 0) return null;
    const text = String(content);

    // Regex matching:
    // 1. URLs (http:// or https://)
    // 2. Task Keys (e.g. TASK-123, ABC-1)
    // 3. Mentions (@Name or @username)
    const tokenRegex = /(https?:\/\/[^\s<]+)|(\b[A-Z]{2,10}-\d+\b)|(@[A-Za-z0-9_.-]+(?:\s[A-Za-z0-9_.-]+)?)/g;

    const elements = [];
    let lastIndex = 0;
    let match;
    let keyIdx = 0;

    while ((match = tokenRegex.exec(text)) !== null) {
        const matchStart = match.index;
        const matchText = match[0];

        // Push text preceding the match
        if (matchStart > lastIndex) {
            elements.push(
                <Fragment key={`txt-${keyIdx++}`}>
                    {text.slice(lastIndex, matchStart)}
                </Fragment>
            );
        }

        // Match 1: URL
        if (match[1]) {
            elements.push(
                <a
                    key={`url-${keyIdx++}`}
                    href={match[1]}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={(e) => e.stopPropagation()}
                    className="text-[var(--accent-soft-text)] underline hover:text-[var(--accent)]"
                >
                    {match[1]}
                </a>
            );
        }
        // Match 2: Task Key (e.g. TASK-123)
        else if (match[2]) {
            elements.push(
                <TaskBadge
                    key={`task-${keyIdx++}`}
                    taskKey={match[2]}
                    projectId={projectId}
                />
            );
        }
        // Match 3: Mention (@someone)
        else if (match[3]) {
            const rawName = match[3].slice(1).trim();
            elements.push(
                <UserTooltip key={`mention-${keyIdx++}`} name={rawName}>
                    <span className="inline-flex items-center gap-0.5 rounded bg-[var(--accent-soft)] px-1.5 py-0.5 font-medium text-[var(--accent-soft-text)] transition-colors hover:bg-[var(--accent)] hover:text-white cursor-pointer">
                        <span className="text-[0.7rem] font-bold opacity-75">@</span>
                        <span>{rawName}</span>
                    </span>
                </UserTooltip>
            );
        }

        lastIndex = matchStart + matchText.length;
    }

    // Push remaining text
    if (lastIndex < text.length) {
        elements.push(
            <Fragment key={`txt-end`}>
                {text.slice(lastIndex)}
            </Fragment>
        );
    }

    return (
        <span className={`whitespace-pre-wrap break-words ${className}`}>
            {elements}
        </span>
    );
}

export default memo(HighlightedContent);
