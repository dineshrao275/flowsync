import { memo } from 'react';

/**
 * Normalizes status strings (e.g. 'To Do', 'In Progress', 'Done', 'Blocked', 'Approved')
 * and maps them to semantic tones: success, info, warning, danger, neutral.
 */
export function getStatusTone(status) {
    if (!status) return 'neutral';
    const s = String(typeof status === 'string' ? status : status?.name ?? '').trim().toLowerCase();

    if (['done', 'completed', 'resolved', 'closed', 'approved', 'verified', 'paid', 'success', 'passed'].some((k) => s.includes(k))) {
        return 'success';
    }
    if (['blocked', 'rejected', 'failed', 'breached', 'overdue', 'cancelled', 'danger'].some((k) => s.includes(k))) {
        return 'danger';
    }
    if (['in progress', 'progress', 'doing', 'active', 'started', 'working', 'in review', 'reviewing'].some((k) => s.includes(k))) {
        return 'info';
    }
    if (['pending', 'requested', 'waiting', 'draft', 'submitted', 'warning'].some((k) => s.includes(k))) {
        return 'warning';
    }
    return 'neutral';
}

const TONE_CLASSES = {
    success: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800/80',
    info: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800/80',
    warning: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800/80',
    danger: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800/80',
    neutral: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
};

const DOT_CLASSES = {
    success: 'bg-emerald-500',
    info: 'bg-blue-500',
    warning: 'bg-amber-500',
    danger: 'bg-rose-500',
    neutral: 'bg-slate-400',
};

function StatusBadge({ status, tone, showDot = true, className = '', ...props }) {
    if (!status && status !== 0) return null;
    const label = typeof status === 'string' ? status : status?.name ?? String(status);
    const resolvedTone = tone || getStatusTone(label);
    const toneStyle = TONE_CLASSES[resolvedTone] ?? TONE_CLASSES.neutral;
    const dotStyle = DOT_CLASSES[resolvedTone] ?? DOT_CLASSES.neutral;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-semibold tracking-wide transition-colors ${toneStyle} ${className}`}
            {...props}
        >
            {showDot && <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${dotStyle}`} aria-hidden="true" />}
            <span className="truncate">{label}</span>
        </span>
    );
}

export default memo(StatusBadge);
