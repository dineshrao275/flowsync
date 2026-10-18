/** Thin completion bar. `value` of `max`; exposes role=progressbar with the percentage as its value. */
export default function ProgressBar({ value, max, label, showPercent = false, tone = 'accent', className = '' }) {
    const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
    const fill = tone === 'danger' ? 'bg-red-500' : tone === 'success' || pct === 100 ? 'bg-emerald-500' : 'bg-[var(--accent)]';

    return (
        <div className={`flex items-center gap-2 ${className}`}>
            <div
                role="progressbar"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={pct}
                className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-gray-200"
            >
                <div className={`h-full rounded-full transition-all ${fill}`} style={{ width: `${pct}%` }} />
            </div>
            {showPercent && <span className="w-9 shrink-0 text-right text-xs tabular-nums text-gray-500">{pct}%</span>}
        </div>
    );
}
