import { useEffect, useState } from 'react';
import api from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import TimeSummary from '../components/time/TimeSummary';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';

function Distribution({ title, subtitle, items }) {
    const max = Math.max(...items.map((item) => item.count), 1);

    return (
        <Card title={title} subtitle={subtitle}>
            <ul className="space-y-2">
                {items.length === 0 ? (
                    <li className="py-6 text-center text-sm text-gray-400">No data.</li>
                ) : (
                    items.map((item) => (
                        <li key={item.key} className="text-sm">
                            <div className="flex items-center justify-between gap-3">
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: item.color }} />
                                    <span className="truncate font-medium text-gray-700">{item.label}</span>
                                </span>
                                <span className="shrink-0 text-xs text-gray-400">
                                    {item.open} open · {item.done} done
                                </span>
                                <span className="shrink-0 font-semibold text-gray-900">{item.count}</span>
                            </div>
                            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    className="h-full rounded-full"
                                    style={{ width: `${Math.max(2, (item.count / max) * 100)}%`, backgroundColor: item.color }}
                                />
                            </div>
                        </li>
                    ))
                )}
            </ul>
        </Card>
    );
}

export default function Reports() {
    usePageTitle('Reports');
    const setCrumbs = useSetCrumbs();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [workspaces, setWorkspaces] = useState([]);
    const [projects, setProjects] = useState([]);
    const [scope, setScope] = useState({ workspace_id: '', project_id: '' });

    useEffect(() => {
        setCrumbs([{ label: 'Reports' }]);
    }, [setCrumbs]);

    useEffect(() => {
        api.get('/reports/overview')
            .then(({ data: response }) => setData(response))
            .catch(() => setError('Unable to load reports.'));
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

    const totals = [
        { label: 'Total tasks', value: data.totals.total },
        { label: 'Open', value: data.totals.open },
        { label: 'Done', value: data.totals.done },
        { label: 'Overdue', value: data.totals.overdue, danger: data.totals.overdue > 0 },
    ];

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Reports</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Distribution and time overview across the tasks you can see.
                </p>
            </div>

            <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                {totals.map((total, index) => (
                    <div
                        key={total.label}
                        className="animate-fade-in-up rounded-xl border border-gray-200/70 p-5 shadow-sm"
                        style={{ backgroundColor: 'var(--card-bg)', animationDelay: `${index * 50}ms` }}
                    >
                        <p className="text-sm text-gray-500">{total.label}</p>
                        <p className={`mt-1 text-2xl font-bold ${total.danger ? 'text-red-600' : 'text-gray-900'}`}>{total.value}</p>
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Distribution title="By status" subtitle="Open vs done per status" items={data.by_status} />
                <Distribution title="By priority" subtitle="Open vs done per priority" items={data.by_priority} />
                <Distribution title="By assignee" subtitle="Where the work sits" items={data.by_assignee} />
                <Distribution title="By project" subtitle="Task volume per project" items={data.by_project} />
            </div>

            <Card title="Time logged" subtitle="Work logs for a workspace or project of your choice">
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
            </Card>
        </div>
    );
}