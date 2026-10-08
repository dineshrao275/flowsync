import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const NPS_COLORS = { Promoters: '#059669', Passives: '#d97706', Detractors: '#dc2626' };

/**
 * One question's aggregates as bars: scale averages, NPS bands, yes/no
 * splits, and choice counts. Text answers never chart — anonymous ones
 * arrive as counts only, identified ones read as a list beside this, and
 * neither shape belongs in a bar.
 */
export default function ResultsChart({ result }) {
    const aggregates = result.aggregates ?? {};
    const type = result.type;

    function data() {
        if (type === 'nps') {
            return [
                { name: 'Promoters', value: aggregates.promoters ?? 0 },
                { name: 'Passives', value: aggregates.passives ?? 0 },
                { name: 'Detractors', value: aggregates.detractors ?? 0 },
            ];
        }

        if (type === 'yes_no') {
            return [
                { name: 'Yes', value: aggregates.yes ?? 0 },
                { name: 'No', value: aggregates.no ?? 0 },
            ];
        }

        if (type === 'multiple_choice') {
            return Object.entries(aggregates).map(([name, value]) => ({ name, value }));
        }

        return [{ name: 'Average', value: aggregates.average ?? 0 }];
    }

    const rows = data();

    if (rows.every((row) => !row.value)) {
        return <p className="text-sm text-gray-400">No answers charted yet.</p>;
    }

    return (
        <div>
            <ResponsiveContainer width="100%" height={Math.max(120, rows.length * 44)}>
                <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                    <XAxis type="number" allowDecimals={false} />
                    <YAxis type="category" dataKey="name" width={110} tick={{ fontSize: 12 }} />
                    <Tooltip />
                    <Bar dataKey="value" fill="#4f46e5">
                        {rows.map((row) => (
                            <Cell key={row.name} fill={NPS_COLORS[row.name] ?? '#4f46e5'} />
                        ))}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
            {type === 'nps' && aggregates.score !== undefined && aggregates.score !== null && (
                <p className="mt-1 text-sm text-gray-600">NPS score: <strong>{aggregates.score}</strong></p>
            )}
            {type === 'scale' && aggregates.average !== undefined && aggregates.average !== null && (
                <p className="mt-1 text-sm text-gray-600">Average: <strong>{aggregates.average}</strong> across {result.response_count ?? 0} answers</p>
            )}
        </div>
    );
}
