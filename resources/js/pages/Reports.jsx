import { useEffect, useState } from 'react';
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
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';

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

function TotalsDonut({ open, done }) {
    if (open + done === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">No tasks in this range.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={200}>
            <PieChart>
                <Pie data={[{ name: 'Open', value: open }, { name: 'Done', value: done }]} dataKey="value" nameKey="name" innerRadius={52} outerRadius={80} paddingAngle={2}>
                    <Cell fill="#94a3b8" />
                    <Cell fill="#6366f1" />
                </Pie>
                <Tooltip {...tooltipProps} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

function StatusBars({ items }) {
    const data = items.map((item) => ({ name: item.label, Open: item.open, Done: item.done }));

    if (data.length === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">No statuses in this range.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 44)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                <XAxis type="number" allowDecimals={false} {...axisProps} />
                <YAxis type="category" dataKey="name" width={110} {...axisProps} />
                <Tooltip {...tooltipProps} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <BarShape dataKey="Open" stackId="a" fill="#94a3b8" />
                <BarShape dataKey="Done" stackId="a" fill="#6366f1" radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function SharePie({ items, emptyText }) {
    const data = items.map((item) => ({ name: item.label, value: item.count, color: item.color || '#94a3b8' }));

    if (data.every((d) => d.value === 0) || data.length === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">{emptyText}</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <PieChart>
                <Pie data={data} dataKey="value" nameKey="name" outerRadius={80}>
                    {data.map((row) => (
                        <Cell key={row.name} fill={row.color} />
                    ))}
                </Pie>
                <Tooltip {...tooltipProps} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

function TotalsBars({ items, emptyText, color = '#0ea5e9' }) {
    const data = items.slice(0, 10).map((item) => ({ name: item.label, Total: item.count }));

    if (data.length === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">{emptyText}</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(160, data.length * 40)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                <XAxis type="number" allowDecimals={false} {...axisProps} />
                <YAxis type="category" dataKey="name" width={110} {...axisProps} />
                <Tooltip {...tooltipProps} />
                <BarShape dataKey="Total" fill={color} radius={[0, 4, 4, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}

function Distribution({ title, subtitle, items }) {
    return (
        <section>
            <div className="mb-2">
                <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
                {subtitle && <p className="text-xs text-gray-500">{subtitle}</p>}
            </div>
            <Table>
                <thead>
                    <tr>
                        <Th>Label</Th>
                        <Th align="right">Open</Th>
                        <Th align="right">Done</Th>
                        <Th align="right">Total</Th>
                    </tr>
                </thead>
                <tbody>
                    {items.length === 0 ? (
                        <TableEmpty colSpan={4}>No data.</TableEmpty>
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
    usePageTitle('Reports');
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
        setCrumbs([{ label: 'Reports' }]);
    }, [setCrumbs]);

    useEffect(() => {
        setData(null);
        setError(null);
        api.get('/reports/overview', { params: { from: range.from, to: range.to } })
            .then(({ data: response }) => setData(response))
            .catch(() => setError('Unable to load reports.'));
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
                <h2 className="text-2xl font-bold text-gray-900">Reports</h2>
                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                    Distribution and time overview across the tasks you can see.
                    <span
                        className="rounded-full px-2 py-0.5 text-[11px] font-medium text-gray-600"
                        style={{ backgroundColor: '#6366f122' }}
                        title="The distributions below are scoped to what your role can see"
                    >
                        {data.scope === 'all' ? 'Showing: every task in the tenant' : 'Showing: tasks in your projects'}
                    </span>
                </p>
            </div>

            <StatRow
                stats={[
                    { label: 'Total tasks', value: data.totals.total },
                    { label: 'Open', value: data.totals.open },
                    { label: 'Done', value: data.totals.done },
                    { label: 'Overdue', value: data.totals.overdue, tone: data.totals.overdue > 0 ? 'danger' : undefined },
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
                    <h3 className="text-sm font-semibold text-gray-900">Open vs done</h3>
                    <p className="mb-2 text-xs text-gray-500">Tasks created or completed {range.from} → {range.to}</p>
                    <TotalsDonut open={data.totals.open} done={data.totals.done} />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">By priority</h3>
                    <p className="mb-2 text-xs text-gray-500">Share of tasks per priority</p>
                    <SharePie items={data.by_priority} emptyText="No priorities in this range." />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">By status</h3>
                    <p className="mb-2 text-xs text-gray-500">Open vs done per status</p>
                    <StatusBars items={data.by_status} />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">By project</h3>
                    <p className="mb-2 text-xs text-gray-500">Task volume per project (top 10)</p>
                    <TotalsBars items={data.by_project} emptyText="No projects in this range." color="#0ea5e9" />
                </section>
                <section className="rounded-xl border border-gray-200/70 p-4 shadow-sm lg:col-span-2" style={{ backgroundColor: 'var(--card-bg)' }}>
                    <h3 className="text-sm font-semibold text-gray-900">By assignee</h3>
                    <p className="mb-2 text-xs text-gray-500">Where the work sits (top 10)</p>
                    <TotalsBars items={data.by_assignee} emptyText="No assignees in this range." color="#6366f1" />
                </section>
            </div>

            <div className="space-y-6">
                <Distribution title="By status" subtitle="Open vs done per status" items={data.by_status} />
                <Distribution title="By priority" subtitle="Open vs done per priority" items={data.by_priority} />
                <Distribution title="By assignee" subtitle="Where the work sits" items={data.by_assignee} />
                <Distribution title="By project" subtitle="Task volume per project" items={data.by_project} />
            </div>

            {hasModule('time_tracking') && (
                <section>
                    <div className="mb-3">
                        <h3 className="text-sm font-semibold text-gray-900">Time logged</h3>
                        <p className="text-xs text-gray-500">Work logs for a workspace or project of your choice</p>
                    </div>
                    <div className="mb-4 flex flex-wrap items-end gap-3">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Workspace</label>
                            <select
                                className={fieldClass}
                                value={scope.workspace_id}
                                onChange={(e) => setScope({ workspace_id: e.target.value, project_id: '' })}
                            >
                                <option value="">Choose a workspace…</option>
                                {workspaces.map((workspace) => (
                                    <option key={workspace.id} value={workspace.id}>
                                        {workspace.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium text-gray-700">Project</label>
                            <select
                                className={fieldClass}
                                value={scope.project_id}
                                disabled={!scope.workspace_id}
                                onChange={(e) => setScope((s) => ({ ...s, project_id: e.target.value }))}
                            >
                                <option value="">Whole workspace</option>
                                {projects.map((project) => (
                                    <option key={project.id} value={project.id}>
                                        {project.name} ({project.key})
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {timeUrl ? <TimeSummary key={timeUrl} url={timeUrl} /> : <p className="py-8 text-center text-sm text-gray-400">Pick a workspace or project above.</p>}
                </section>
            )}
        </div>
    );
}
