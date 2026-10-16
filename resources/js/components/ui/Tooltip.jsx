import { cloneElement, useId, useState } from 'react';

/**
 * Hover/focus hint for icon-only controls (replaces `title=`, which is
 * unreachable by keyboard and touch). The trigger keeps its own accessible
 * name; the tooltip is supplementary and wired with aria-describedby.
 * Escape dismisses it (WCAG 1.4.13).
 */
export default function Tooltip({ content, children, side = 'top' }) {
    const id = useId();
    const [open, setOpen] = useState(false);

    if (!content) return children;

    const show = () => setOpen(true);
    const hide = () => setOpen(false);
    const position = side === 'bottom' ? 'top-full mt-1.5' : side === 'right' ? 'left-full top-1/2 ml-1.5 -translate-y-1/2' : 'bottom-full mb-1.5';
    const horizontal = side === 'right' ? '' : 'left-1/2 -translate-x-1/2';

    return (
        <span className="relative inline-flex" onMouseEnter={show} onMouseLeave={hide} onFocus={show} onBlur={hide} onKeyDown={(e) => e.key === 'Escape' && hide()}>
            {cloneElement(children, { 'aria-describedby': open ? id : undefined })}
            {open && (
                <span
                    id={id}
                    role="tooltip"
                    className={`pointer-events-none absolute z-[70] whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-caption font-medium text-white shadow-popover ${position} ${horizontal}`}
                >
                    {content}
                </span>
            )}
        </span>
    );
}
