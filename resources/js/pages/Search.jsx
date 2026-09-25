import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../services/api';
import Button from '../components/ui/Button';
import Pagination from '../components/ui/Pagination';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';
import { projectUrl } from '../utils/deepLinks';

export default function Search() {
    usePageTitle('Search');
    const setCrumbs = useSetCrumbs();
    const [filters, setFilters] = useState({});
    const [params, setParams] = useState({});
    const [options, setOptions] = useState({
        statuses: [],
        priorities: [],
        assignees: [],
        projects: [],
        workspaces: [],
        labels: [],
    });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [page, setPage] = useState(1);

    useEffect(() => {
        setCrumbs([{ label: 'Search' }]);
    }, [setCrumbs]);

    const load = useCallback((targetPage, queryParams) => {
        setLoading(true);
        setError(null);
        api.get('/search/tasks', { params: { ...queryParams, page: targetPage, per_page: 25 } })
            .then(({ data: response }) => {
                setData(response);
                if (response.filters) setOptions(response.filters);
            })
            .catch(() => setError('Unable to run the search.'))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        load(1, {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function submit(e) {
        e.preventDefault();
        const clean = {};
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '' && value !== undefined && value !== null) clean[key] = value;
        });
        setParams(clean);
        setPage(1);
        load(1, clean);
    }

    function reset() {
        setFilters({});
        setParams({});
        setPage(1);
        load(1, {});
    }

    const tasks = data?.tasks ?? [];
    const pagination = data?.pagination ?? null;

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Search tasks</h2>
                <p className="mt-1 text-sm text-gray-500">
                    Find tasks across every project you belong to.
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                <div className="min-w-56 flex-1">
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Query</label>
                    <input
                        className={fieldClass}
                        placeholder="Title, key or description…"
                        value={filters.q ?? ''}
                        onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
                    />
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Workspace</label>
                    <select className={fieldClass} value={filters.workspace_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, workspace_id: e.target.value }))}>
                        <option value="">Any</option>
                        {options.workspaces.map((workspace) => (
                            <option key={workspace.id} value={workspace.id}>
                                {workspace.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Project</label>
                    <select className={fieldClass} value={filters.project_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, project_id: e.target.value }))}>
                        <option value="">Any</option>
                        {options.projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
                    <select className={fieldClass} value={filters.status_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, status_id: e.target.value }))}>
                        <option value="">Any</option>
                        {options.statuses.map((status) => (
                            <option key={status.id} value={status.id}>
                                {status.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Priority</label>
                    <select className={fieldClass} value={filters.priority_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, priority_id: e.target.value }))}>
                        <option value="">Any</option>
                        {options.priorities.map((priority) => (
                            <option key={priority.id} value={priority.id}>
                                {priority.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Assignee</label>
                    <select className={fieldClass} value={filters.assignee_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, assignee_id: e.target.value }))}>
                        <option value="">Anyone</option>
                        {options.assignees.map((assignee) => (
                            <option key={assignee.id} value={assignee.id}>
                                {assignee.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            checked={filters.label_id !== undefined && filters.label_id !== ''}
                            onChange={(e) => setFilters((f) => ({ ...f, label_id: e.target.checked ? options.labels[0]?.id ?? '' : '' }))}
                        />
                        Has label
                    </label>
                    <select
                        className={fieldClass}
                        value={filters.label_id ?? ''}
                        disabled={!options.labels.length}
                        onChange={(e) => setFilters((f) => ({ ...f, label_id: e.target.value }))}
                    >
                        <option value="">Any label</option>
                        {options.labels.map((label) => (
                            <option key={label.id} value={label.id}>
                                {label.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Due from</label>
                    <input type="date" className={fieldClass} value={filters.due_from ?? ''} onChange={(e) => setFilters((f) => ({ ...f, due_from: e.target.value }))} />
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Due to</label>
                    <input type="date" className={fieldClass} value={filters.due_to ?? ''} onChange={(e) => setFilters((f) => ({ ...f, due_to: e.target.value }))} />
                </div>
                <Button type="submit">Search</Button>
                <Button type="button" variant="secondary" onClick={reset}>
                    Reset
                </Button>
            </form>

            <Table>
                <thead>
                    <tr>
                        <Th>Task</Th>
                        <Th>Project</Th>
                        <Th>Workspace</Th>
                        <Th>Labels</Th>
                        <Th>Status</Th>
                        <Th>Assignee</Th>
                        <Th align="right">Due</Th>
                    </tr>
                </thead>
                <tbody>
                    {loading ? (
                        <tr>
                            <Td colSpan={7} className="py-12 text-center">
                                <Spinner />
                            </Td>
                        </tr>
                    ) : tasks.length === 0 ? (
                        <TableEmpty colSpan={7}>
                            No tasks match{Object.keys(params).length ? ' these filters' : ''}.
                        </TableEmpty>
                    ) : (
                        tasks.map((task, index) => (
                            <tr
                                key={task.id}
                                className="animate-fade-in transition-colors duration-150 hover:bg-gray-50"
                                style={{ animationDelay: `${index * 30}ms` }}
                            >
                                <Td>
                                    <Link to={projectUrl(task.project.id, 'tasks')} className="flex min-w-0 items-start gap-2">
                                        <span
                                            className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                                            style={{ backgroundColor: task.priority?.color || '#94a3b8' }}
                                        />
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium text-gray-800">
                                                <span className="text-gray-400">{task.project?.key}</span> · {task.title}
                                            </span>
                                        </span>
                                    </Link>
                                </Td>
                                <Td className="whitespace-nowrap">{task.project?.name}</Td>
                                <Td className="whitespace-nowrap">{task.workspace?.name}</Td>
                                <Td>
                                    <span className="flex flex-wrap gap-1">
                                        {task.labels.map((label) => (
                                            <span
                                                key={label.id}
                                                className="rounded-full px-2 py-0.5 text-xs font-medium"
                                                style={{ backgroundColor: `${label.color}22`, color: label.color }}
                                            >
                                                {label.name}
                                            </span>
                                        ))}
                                    </span>
                                </Td>
                                <Td className="whitespace-nowrap" style={{ color: task.status?.color }}>
                                    {task.status?.name}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    {task.assignee?.name ?? <span className="text-gray-400">Unassigned</span>}
                                </Td>
                                <Td align="right" className="whitespace-nowrap">
                                    {task.due_date ? (
                                        <span
                                            className={
                                                task.due_date < new Date().toISOString().slice(0, 10) && !task.completed_at
                                                    ? 'font-medium text-red-500'
                                                    : 'text-gray-500'
                                            }
                                        >
                                            {task.due_date}
                                        </span>
                                    ) : (
                                        <span className="text-gray-400">—</span>
                                    )}
                                </Td>
                            </tr>
                        ))
                    )}
                </tbody>
            </Table>

            {pagination && (
                <Pagination
                    page={page}
                    pages={pagination.last_page}
                    total={pagination.total}
                    onChange={(p) => {
                        setPage(p);
                        load(p, params);
                    }}
                />
            )}
        </div>
    );
}