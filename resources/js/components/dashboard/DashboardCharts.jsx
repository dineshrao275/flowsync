import { useTranslation } from 'react-i18next';
import { Bar as BarShape, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import i18n, { intlTag } from '../../i18n';
import Button from '../ui/Button';
import EmptyState from '../ui/EmptyState';
import { chartAxisProps as axisProps, chartPalette, chartTooltipProps as tooltipProps } from '../../utils/charts';

export function chartDate(iso) {
    const d = new Date(`${iso}T00:00:00`);
    return d.toLocaleDateString(intlTag(i18n.language), { month: 'short', day: 'numeric' });
}

export function formatHours(value, t) {
    return t('dashboard.hoursValue', { value: Number(value).toFixed(1) });
}

// The admin's accent lives on a CSS token, so charts read it back instead
// of hardcoding a brand hex — a re-themed tenant re-skins its analytics.
export function ChartEmpty({ title, actionLabel, onAction }) {
    return (
        <EmptyState
            title={title}
            action={onAction ? <Button size="sm" variant="subtle" onClick={onAction}>{actionLabel}</Button> : null}
        />
    );
}

export function ProjectProgressChart({ projects, mode, onBrowse }) {
    const { t } = useTranslation();
    const palette = chartPalette(mode);
    const openLabel = t('dashboard.charts.open');
    const doneLabel = t('dashboard.charts.done');

    if (projects.length === 0) {
        return <ChartEmpty title={t('dashboard.charts.emptyProjects')} actionLabel={t('dashboard.charts.browseProjects')} onAction={onBrowse} />;
    }
    const data = projects.map((p) => ({ name: p.name, [openLabel]: p.open, [doneLabel]: p.done }));

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 44)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={palette.grid} />
                <XAxis type="number" allowDecimals={false} {...axisProps(mode)} />
                <YAxis type="category" dataKey="name" width={90} {...axisProps(mode)} />
                <Tooltip {...tooltipProps(mode)} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <BarShape dataKey={openLabel} stackId="a" fill={palette.secondary} radius={[0, 0, 0, 0]} />
                <BarShape dataKey={doneLabel} stackId="a" fill={palette.accent} radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

export function HoursLoggedChart({ daily, mode, onBrowse }) {
    const { t } = useTranslation();
    const palette = chartPalette(mode);
    const data = daily.map((d) => ({ date: chartDate(d.date), hours: Math.round((d.minutes / 60) * 10) / 10 }));

    if (data.every((d) => d.hours === 0)) {
        return <ChartEmpty title={t('dashboard.charts.emptyHours')} actionLabel={t('dashboard.charts.browseProjects')} onAction={onBrowse} />;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <BarChart data={data} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={palette.grid} />
                <XAxis dataKey="date" interval={Math.max(0, Math.ceil(data.length / 8) - 1)} {...axisProps(mode)} />
                <YAxis allowDecimals={false} {...axisProps(mode)} />
                <Tooltip {...tooltipProps(mode)} formatter={(value) => [formatHours(value, t), t('dashboard.charts.logged')]} />
                <BarShape dataKey="hours" fill={palette.accent} radius={[4, 4, 0, 0]} maxBarSize={28} />
            </BarChart>
        </ResponsiveContainer>
    );
}

export function TasksCreatedChart({ daily, mode, onBrowse }) {
    const { t } = useTranslation();
    const palette = chartPalette(mode);
    const data = daily.map((d) => ({ date: chartDate(d.date), created: d.count }));

    if (data.every((d) => d.created === 0)) {
        return <ChartEmpty title={t('dashboard.charts.emptyTasks')} actionLabel={t('dashboard.charts.browseProjects')} onAction={onBrowse} />;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <LineChart data={data} margin={{ top: 4, right: 8, left: -18, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke={palette.grid} />
                <XAxis dataKey="date" interval={Math.max(0, Math.ceil(data.length / 8) - 1)} {...axisProps(mode)} />
                <YAxis allowDecimals={false} {...axisProps(mode)} />
                <Tooltip {...tooltipProps(mode)} />
                <Line
                    type="monotone"
                    dataKey="created"
                    stroke={palette.accent}
                    strokeWidth={2}
                    dot={data.length > 60 ? false : { r: 2.5, fill: palette.accent }}
                    activeDot={{ r: 4 }}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}
