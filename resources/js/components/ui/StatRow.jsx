/**
 * Slim KPI row — one tile per metric: big number, label, optional hint/tone.
 */
export default function StatRow({ stats = [], className = '' }) {
    if (!stats.length) return null;

    return (
        <div className={`grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-6 ${className}`}>
            {stats.map((stat) => (
                <div key={stat.label} className="min-w-0">
                    <p className="truncate text-[11px] font-medium text-muted">{stat.label}</p>
                    <p
                        className={`mt-1 text-[24px] font-bold leading-none tracking-[-0.02em] tabular-nums ${
                            stat.tone === 'danger' ? 'text-[var(--status-danger)]' : stat.tone === 'success' ? 'text-[var(--status-success)]' : 'text-ink'
                        }`}
                    >
                        {stat.value}
                    </p>
                    {stat.hint && <p className="mt-1 truncate text-[11px] text-[var(--text-faint)]">{stat.hint}</p>}
                </div>
            ))}
        </div>
    );
}
