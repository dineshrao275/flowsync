import { useId, useState } from 'react';
import { formatFullDateTime, formatRelativeTime } from '../../utils/format';

/**
 * Interactive timestamp component:
 * 1. Displays time in human-readable relative format (e.g., "just now", "5 minutes ago", "yesterday", "Oct 12")
 * 2. On hover/focus, displays an accessible, high-contrast floating tooltip with the exact full date and time.
 */
export default function Timestamp({
    date,
    prefix = '',
    suffix = '',
    fallback = '—',
    short = false,
    className = '',
    tooltipClassName = '',
    placement = 'top',
}) {
    const tooltipId = useId();
    const [hovered, setHovered] = useState(false);

    if (!date) {
        return <span className={className}>{fallback}</span>;
    }

    const d = new Date(date);
    if (Number.isNaN(d.getTime())) {
        return <span className={className}>{fallback}</span>;
    }

    const relativeText = formatRelativeTime(date, { short, fallback });
    const fullText = formatFullDateTime(date, fallback);
    const isoString = d.toISOString();
    const isBottom = placement === 'bottom';

    return (
        <span
            className={`group relative inline-flex items-center text-inherit cursor-help ${className}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
            onFocus={() => setHovered(true)}
            onBlur={() => setHovered(false)}
            tabIndex={0}
            aria-describedby={tooltipId}
        >
            <time
                dateTime={isoString}
                className="underline decoration-dotted decoration-gray-400/60 dark:decoration-gray-500 underline-offset-2 transition group-hover:decoration-indigo-500 group-hover:text-indigo-600 dark:group-hover:text-indigo-400"
            >
                {prefix}{relativeText}{suffix}
            </time>

            {/* High-contrast, theme-designed tooltip with sharp visibility and arrow */}
            <span
                id={tooltipId}
                role="tooltip"
                className={`pointer-events-none absolute left-1/2 -translate-x-1/2 whitespace-nowrap z-50 rounded-lg px-2.5 py-1.5 text-xs font-semibold tracking-tight shadow-xl ring-1 transition-all duration-150 ${
                    isBottom ? 'top-full mt-2' : 'bottom-full mb-2'
                } bg-slate-900 text-white ring-white/10 dark:bg-slate-800 dark:text-slate-100 dark:ring-white/20 ${
                    hovered ? 'visible opacity-100 scale-100' : 'invisible opacity-0 scale-95'
                } ${tooltipClassName}`}
            >
                {fullText}
                <span
                    aria-hidden="true"
                    className={`absolute left-1/2 -translate-x-1/2 border-4 border-transparent ${
                        isBottom
                            ? '-top-2 border-b-slate-900 dark:border-b-slate-800'
                            : '-bottom-2 border-t-slate-900 dark:border-t-slate-800'
                    }`}
                />
            </span>
        </span>
    );
}
