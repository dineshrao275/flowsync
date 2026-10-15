import { Bar as BarShape, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const axisProps = { tick: { fontSize: 11, fill: '#9aa3b2' }, tickMargin: 8 };
const tooltipProps = {
    contentStyle: {
        backgroundColor: '#ffffff',
        border: '1px solid #e5e7eb',
        borderRadius: '8px',
        fontSize: '12px',
        boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
    },
    cursor: { fill: 'rgba(99,102,241,0.06)' },
};

/**
 * The analytics page's shared primitives: a stat-tile grid in the
 * Dashboard widget pattern, and one horizontal bar chart with the same
 * axis/tooltip dressing. Tabs compose these over their own payloads —
 * no tab invents its own tile or chart styling.
 */
export function Tiles({ items }) {
    return (
        <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
            {items.map((stat, index) => (
                <div
                    key={stat.label}
                    className="animate-fade-in-up rounded-xl border border-gray-200/70 p-4 shadow-sm"
                    style={{ backgroundColor: 'var(--card-bg)', animationDelay: `${index * 50}ms` }}
                >
                    <p className="truncate text-xs font-medium text-gray-500">{stat.label}</p>
                    <p className="mt-1 truncate text-2xl font-bold text-gray-900" title={String(stat.value ?? '')}>
                        {stat.value ?? '—'}
                    </p>
                    {stat.hint && <p className="mt-0.5 truncate text-xs text-gray-400">{stat.hint}</p>}
                </div>
            ))}
        </div>
    );
}

/**
 * One horizontal bar chart over `{ name, value }` rows. Zero rows render a
 * sentence, never an empty frame that reads as broken (the Dashboard rule).
 */
export function HBar({ data, color = '#4f46e5', format = (value) => value, emptyHint = 'Nothing to chart yet.' }) {
    if (!data || data.length === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">{emptyHint}</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 44)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                <XAxis type="number" allowDecimals={false} {...axisProps} />
                <YAxis type="category" dataKey="name" width={110} {...axisProps} />
                <Tooltip {...tooltipProps} formatter={(value) => format(value)} />
                <BarShape dataKey="value" fill={color} radius={[0, 4, 4, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}
