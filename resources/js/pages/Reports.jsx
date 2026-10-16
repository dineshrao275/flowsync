import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Bar as BarShape,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import StatRow from '../components/ui/StatRow';
import TimeSummary from '../components/time/TimeSummary';
import RangeFilter from '../components/reports/RangeFilter';
import { resolveRange } from '../utils/dateRange';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import EmptyState from '../components/ui/EmptyState';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';
import { chartAxisProps, chartPalette, chartTooltipProps } from '../utils/charts';

function TotalsDonut({ open, done }) {
    const { t } = useTranslation();
    const mode = useTheme().resolvedMode;
    const palette = chartPalette(mode);
    const openLabel = t('dashboard.charts.open');
    const doneLabel = t('dashboard.charts.done');

    if (open + done === 0) {
        return <EmptyState description={t('dashboard.reports.emptyTasks')} />;
    }

    return (
        <ResponsiveContainer width="100%" height={200}>
            <PieChart>
                <Pie data={[{ name: openLabel, value: open }, { name: doneLabel, value: done }]} dataKey="value" nameKey="name" innerRadius={52} outerRadius={80} paddingAngle={2}>
                    <Cell fill={palette.secondary} />
                    <Cell fill={palette.accent} />
                </Pie>
                <Tooltip {...chartTooltipProps(mode)} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

function StatusBars({ items }) {
    const { t } = useTranslation();
    const mode = useTheme().resolvedMode;
    const palette = chartPalette(mode);
    const openLabel = t('dashboard.charts.open');
    const doneLabel = t('dashboard.charts.done');
    const data = items.map((item) => ({ name: item.label, [openLabel]: item.open, [doneLabel]: item.done }));

    if (data.length === 0) {
        return <EmptyState description={t('dashboard.reports.emptyStatuses')} />;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 44)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={palette.grid} />
                <XAxis type="number" allowDecimals={false} {...chartAxisProps(mode)} />
                <YAxis type="category" dataKey="name" width={110} {...chartAxisProps(mode)} />
                <Tooltip {...chartTooltipProps(mode)} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <BarShape dataKey={openLabel} stackId="a" fill={palette.secondary} />
                <BarShape dataKey={doneLabel} stackId="a" fill={palette.accent} radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function SharePie({ items, emptyText }) {
    const mode = useTheme().resolvedMode;
    const palette = chartPalette(mode);
    const data = items.map((item) => ({ name: item.label, value: item.count, color: item.color || palette.secondary }));

    if (data.every((d) => d.value === 0) || data.length === 0) {
        return <EmptyState description={emptyText} />;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <PieChart>
                <Pie data={data} dataKey="value" nameKey="name" outerRadius={80}>
                    {data.map((row) => (
                        <Cell key={row.name} fill={row.color} />
                    ))}
                </Pie>
                <Tooltip {...chartTooltipProps(mode)} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

function TotalsBars({ items, emptyText, color }) {
    const mode = useTheme().resolvedMode;
    const palette = chartPalette(mode);
    const fill = color || palette.accent;
    const data = items.slice(0, 10).map((item) => ({ name: item.label, Total: item.count }));

    if (data.length === 0) {
        return <EmptyState description={emptyText} />;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 40)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={palette.grid} />
                <XAxis type="number" allowDecimals={false} {...chartAxisProps(mode)} />
                <YAxis type="category" dataKey="name" width={110} {...chartAxisProps(mode)} />
                <Tooltip {...chartTooltipProps(mode)} />
                <BarShape dataKey="Total" fill={fill} radius={[0, 4, 4, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function Distribution({ title, subtitle, items }) {
    const { t } = useTranslation();
    return (
        <section>
            <div className="mb-2">
                <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
                {subtitle && <p className="text-xs text-gray-500">{subtitle}</p>}
            </div>
            <Table>
                <thead>
                    <tr>
                        <Th>{t('dashboard.reports.thLabel')}</Th>
                        <Th align="right">{t('dashboard.reports.thOpen')}</Th>
                        <Th align="right">{t('dashboard.reports.thDone')}</Th>
                        <Th align="right">{t('dashboard.reports.thTotal')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {items.length === 0 ? (
                        <TableEmpty colSpan={4}>{t('dashboard.reports.noData')}</TableEmpty>
                    ) : (
                        items.map((item) => (
                            <tr key={item.key} className="transition-colors duration-150 hover:bg-gray-50/60">
                                <Td>
                                    <span className="flex items-center gap-2">
                                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: item.color }} />
                                        <span className="truncate font-medium text-gray-800">{item.label}</span>
                                    </span>
                                </Td>
                                <Td align="right" className="tabular-nums">{item.open}</Td>
                                <Td align="right" className="tabular-nums">{item.done}</Td>
                                <Td align="right" className="tabular-nums font-semibold text-gray-900">{item.count}</Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>
        </section>
    );
}

export default function Reports() {
    const { t } = useTranslation();
    usePageTitle(t('dashboard.reports.pageTitle'));
    const { hasModule } = useAuth();
    const setCrumbs = useSetCrumbs();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [workspaces, setWorkspaces] = useState([]);
    const [projects, setProjects] = useState([]);
    const [scope, setScope] = useState({ workspace_id: '', project_id: '' });
    const [preset, setPreset] = useState('month');
    const [custom, setCustom] = useState(null);
    const range = resolveRange(preset, custom ?? {});

    useEffect(() => {
        setCrumbs([{ label: t('dashboard.reports.pageTitle') }]);
    }, [setCrumbs, t]);

    useEffect(() => {
        setData(null);
        setError(null);
        api.get('/reports/overview', { params: { from: range.from, to: range.to } })
            .then(({ data: response }) => setData(response))
            .catch(() => setError(t('dashboard.reports.loadFailed')));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [range.from, range.to]);

    useEffect(() => {
        api.get('/workspaces')
            .then(({ data: response }) => setWorkspaces(response.workspaces ?? []))
            .catch(() => {});
    }, []);

    useEffect(() => {
        if (!scope.workspace_id) {
            setProjects([]);
            return;
        }
        api.get(`/workspaces/${scope.workspace_id}/projects`)
            .then(({ data: response }) => setProjects(response.projects ?? []))
            .catch(() => setProjects([]));
    }, [scope.workspace_id]);

    const timeUrl = scope.project_id
        ? `/projects/${scope.project_id}/time-summary`
        : scope.workspace_id
          ? `/workspaces/${scope.workspace_id}/time-summary`
          : null;

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('dashboard.reports.pageTitle')}</h2>
                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                    {t('dashboard.reports.subtitle')}
                    <span
                        className="rounded-full px-2 py-0.5 text-xs font-medium text-gray-600"
                        style={{ backgroundColor: 'var(--accent-soft)' }}
                        title={t('dashboard.scope.title')}
                    >
                        {data.scope === 'all' ? t('dashboard.scope.all') : t('dashboard.scope.mine')}
                    </span>
                </p>
            </div>

            <StatRow
                stats={[
                    { label: t('dashboard.reports.stats.total'), value: data.totals.total },
                    { label: t('dashboard.reports.stats.open'), value: data.totals.open },
                    { label: t('dashboard.reports.stats.done'), value: data.totals.done },
                    { label: t('dashboard.reports.stats.overdue'), value: data.totals.overdue, tone: data.totals.overdue > 0 ? 'danger' : undefined },
                ]}
            />

            <RangeFilter
                preset={preset}
                onChange={(key, customRange) => {
                    setPreset(key);
                    setCustom(customRange);
                }}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">{t('dashboard.reports.openVsDone')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('dashboard.reports.rangeSubtitle', { range: `${range.from} → ${range.to}` })}</p>
                    <TotalsDonut open={data.totals.open} done={data.totals.done} />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">{t('dashboard.reports.byPriority')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('dashboard.reports.prioritySubtitle')}</p>
                    <SharePie items={data.by_priority} emptyText={t('dashboard.reports.emptyPriorities')} />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">{t('dashboard.reports.byStatus')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('dashboard.reports.statusSubtitle')}</p>
                    <StatusBars items={data.by_status} />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">{t('dashboard.reports.byProject')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('dashboard.reports.projectSubtitle')}</p>
                    <TotalsBars items={data.by_project} emptyText={t('dashboard.reports.emptyProjects')} color="#0ea5e9" />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm lg:col-span-2" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">{t('dashboard.reports.byAssignee')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('dashboard.reports.assigneeSubtitle')}</p>
                    <TotalsBars items={data.by_assignee} emptyText={t('dashboard.reports.emptyAssignees')} />
                </section>
            </div>

            <div className="space-y-6">
                <Distribution title={t('dashboard.reports.byStatus')} subtitle={t('dashboard.reports.statusSubtitle')} items={data.by_status} />
                <Distribution title={t('dashboard.reports.byPriority')} subtitle={t('dashboard.reports.prioritySubtitle')} items={data.by_priority} />
                <Distribution title={t('dashboard.reports.byAssignee')} subtitle={t('dashboard.reports.assigneeDistribution')} items={data.by_assignee} />
                <Distribution title={t('dashboard.reports.byProject')} subtitle={t('dashboard.reports.projectDistribution')} items={data.by_project} />
            </div>

            {hasModule('time_tracking') && (
                <section>
                    <div className="mb-3">
                        <h3 className="text-sm font-semibold text-gray-900">{t('dashboard.reports.timeTitle')}</h3>
                        <p className="text-xs text-gray-500">{t('dashboard.reports.timeSubtitle')}</p>
                    </div>
                    <div className="mb-4 flex flex-wrap items-end gap-3">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.reports.workspace')}</label>
                            <select
                                className={fieldClass}
                                value={scope.workspace_id}
                                onChange={(e) => setScope({ workspace_id: e.target.value, project_id: '' })}
                            >
                                <option value="">{t('dashboard.reports.chooseWorkspace')}</option>
                                {workspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>
                                        {workspace.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.reports.project')}</label>
                            <select
                                className={fieldClass}
                                value={scope.project_id}
                                disabled={!scope.workspace_id}
                                onChange={(e) => setScope((s) => ({ ...s, project_id: e.target.value }))}
                            >
                                <option value="">{t('dashboard.reports.wholeWorkspace')}</option>
                                {projects.map((project) => (
                                    <option key={project.id} value={project.id}>
                                        {project.name} ({project.key})
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {timeUrl ? <TimeSummary key={timeUrl} url={timeUrl} /> : <p className="py-8 text-center text-sm text-gray-400">{t('dashboard.reports.pickScope')}</p>}
                </section>
            )}
        </div>
    );
}
