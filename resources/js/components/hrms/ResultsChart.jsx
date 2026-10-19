import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const NPS_COLORS = { Promoters: '#059669', Passives: '#d97706', Detractors: '#dc2626' };

/**
 * One question's aggregates as bars: scale averages, NPS bands, yes/no
 * splits, and choice counts. Text answers never chart — anonymous ones
 * arrive as counts only, identified ones read as a list beside this, and
 * neither shape belongs in a bar.
 *
 * Each row carries a raw `key` alongside its translated `name`: the colour
 * map and the bucket it names are keyed on `key`, so a translated axis
 * label can never repoint a band's colour.
 */
export default function ResultsChart({ result }) {
    const { t } = useTranslation();
    const aggregates = result.aggregates ?? {};
    const type = result.type;

    function data() {
        if (type === 'nps') {
            return [
                { key: 'Promoters', name: t('hrms.resultsChart.promoters'), value: aggregates.promoters ?? 0 },
                { key: 'Passives', name: t('hrms.resultsChart.passives'), value: aggregates.passives ?? 0 },
                { key: 'Detractors', name: t('hrms.resultsChart.detractors'), value: aggregates.detractors ?? 0 },
            ];
        }

        if (type === 'yes_no') {
            return [
                { key: 'Yes', name: t('hrms.resultsChart.yes'), value: aggregates.yes ?? 0 },
                { key: 'No', name: t('hrms.resultsChart.no'), value: aggregates.no ?? 0 },
            ];
        }

        if (type === 'multiple_choice') {
            return Object.entries(aggregates).map(([name, value]) => ({ key: name, name, value }));
        }

        return [{ key: 'average', name: t('hrms.resultsChart.average'), value: aggregates.average ?? 0 }];
    }

    const rows = data();

    if (rows.every((row) => !row.value)) {
        return <p className="text-sm text-gray-400">{t('hrms.resultsChart.noAnswers')}</p>;
    }

    return (
        <div>
            <ResponsiveContainer width="100%" height={Math.max(120, rows.length * 44)}>
                <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                    <XAxis type="number" allowDecimals={false} />
                    <YAxis type="category" dataKey="name" width={110} tick={{ fontSize: 12 }} />
                    <Tooltip />
                    <Bar dataKey="value" fill="#2563eb">
                        {rows.map((row) => (
                            <Cell key={row.key} fill={NPS_COLORS[row.key] ?? '#2563eb'} />
                        ))}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
            {type === 'nps' && aggregates.score !== undefined && aggregates.score !== null && (
                <p className="mt-1 text-sm text-gray-600">{t('hrms.resultsChart.npsScore')} <strong>{aggregates.score}</strong></p>
            )}
            {type === 'scale' && aggregates.average !== undefined && aggregates.average !== null && (
                <p className="mt-1 text-sm text-gray-600">
                    {t('hrms.resultsChart.averageAcross', { value: aggregates.average, count: result.response_count ?? 0 })}
                </p>
            )}
        </div>
    );
}
