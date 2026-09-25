import { useEffect, useState } from 'react';
import api from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import StatRow from '../components/ui/StatRow';
import TimeSummary from '../components/time/TimeSummary';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useAuth } from '../context/AuthContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';

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

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Reports</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Distribution and time overview across the tasks you can see.
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
