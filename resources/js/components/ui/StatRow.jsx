/**
 * Slim KPI row — the borderless replacement for the old stat cards on the
 * dashboards. One tile per metric: big number, label, optional hint/tone.
 */
export default function StatRow({ stats = [], className = '' }) {
    if (!stats.length) return null;

    return (
        <div className={`grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-6 ${className}`}>
            {stats.map((stat) => (
                <div key={stat.label} className="min-w-0">
                    <p className="truncate text-xs font-medium uppercase tracking-wide text-gray-400">{stat.label}</p>
                    <p
                        className={`mt-1 text-2xl font-semibold tabular-nums ${
                            stat.tone === 'danger' ? 'text-red-600' : stat.tone === 'success' ? 'text-emerald-600' : 'text-gray-900'
                        }`}
                    >
                        {stat.value}
                    </p>
                    {stat.hint && <p className="mt-0.5 truncate text-xs text-gray-400">{stat.hint}</p>}
                </div>
            ))}
        </div>
    );
}
