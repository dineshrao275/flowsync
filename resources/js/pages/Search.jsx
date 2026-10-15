import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Pagination from '../components/ui/Pagination';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';
import { projectUrl } from '../utils/deepLinks';

export default function Search() {
    const { t } = useTranslation();
    usePageTitle(t('dashboard.search.pageTitle'));
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
        setCrumbs([{ label: t('dashboard.search.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback((targetPage, queryParams) => {
        setLoading(true);
        setError(null);
        api.get('/search/tasks', { params: { ...queryParams, page: targetPage, per_page: 25 } })
            .then(({ data: response }) => {
                setData(response);
                if (response.filters) setOptions(response.filters);
            })
            .catch(() => setError(t('dashboard.search.loadFailed')))
            .finally(() => setLoading(false));
    }, [t]);

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
                <h2 className="text-2xl font-bold text-gray-900">{t('dashboard.search.title')}</h2>
                <p className="mt-1 text-sm text-gray-500">
                    {t('dashboard.search.subtitle')}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                <div className="min-w-56 flex-1">
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.search.query')}</label>
                    <input
                        className={fieldClass}
                        placeholder={t('dashboard.search.queryPlaceholder')}
                        value={filters.q ?? ''}
                        onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
                    />
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.search.workspace')}</label>
                    <select className={fieldClass} value={filters.workspace_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, workspace_id: e.target.value }))}>
                        <option value="">{t('dashboard.search.any')}</option>
                        {options.workspaces.map((workspace) => (
                            <option key={workspace.id} value={workspace.id}>
                                {workspace.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.search.project')}</label>
                    <select className={fieldClass} value={filters.project_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, project_id: e.target.value }))}>
                        <option value="">{t('dashboard.search.any')}</option>
                        {options.projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.search.status')}</label>
                    <select className={fieldClass} value={filters.status_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, status_id: e.target.value }))}>
                        <option value="">{t('dashboard.search.any')}</option>
                        {options.statuses.map((status) => (
                            <option key={status.id} value={status.id}>
                                {status.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.search.priority')}</label>
                    <select className={fieldClass} value={filters.priority_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, priority_id: e.target.value }))}>
                        <option value="">{t('dashboard.search.any')}</option>
                        {options.priorities.map((priority) => (
                            <option key={priority.id} value={priority.id}>
                                {priority.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.search.assignee')}</label>
                    <select className={fieldClass} value={filters.assignee_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, assignee_id: e.target.value }))}>
                        <option value="">{t('dashboard.search.anyone')}</option>
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
                            className="rounded border-gray-300 accent-[var(--accent)]"
                            checked={filters.label_id !== undefined && filters.label_id !== ''}
                            onChange={(e) => setFilters((f) => ({ ...f, label_id: e.target.checked ? options.labels[0]?.id ?? '' : '' }))}
                        />
                        {t('dashboard.search.hasLabel')}
                    </label>
                    <select
                        className={fieldClass}
                        value={filters.label_id ?? ''}
                        disabled={!options.labels.length}
                        onChange={(e) => setFilters((f) => ({ ...f, label_id: e.target.value }))}
                    >
                        <option value="">{t('dashboard.search.anyLabel')}</option>
                        {options.labels.map((label) => (
                            <option key={label.id} value={label.id}>
                                {label.name}
                            </option>
                        ))}
                    </select>
                </div>
                <Input label={t('dashboard.search.dueFrom')} type="date" value={filters.due_from ?? ''} onChange={(e) => setFilters((f) => ({ ...f, due_from: e.target.value }))} />
                <Input label={t('dashboard.search.dueTo')} type="date" value={filters.due_to ?? ''} onChange={(e) => setFilters((f) => ({ ...f, due_to: e.target.value }))} />
                <Button type="submit">{t('dashboard.search.search')}</Button>
                <Button type="button" variant="secondary" onClick={reset}>
                    {t('dashboard.search.reset')}
                </Button>
            </form>

            <Table>
                <thead>
                    <tr>
                        <Th>{t('dashboard.search.thTask')}</Th>
                        <Th>{t('dashboard.search.thProject')}</Th>
                        <Th>{t('dashboard.search.thWorkspace')}</Th>
                        <Th>{t('dashboard.search.thLabels')}</Th>
                        <Th>{t('dashboard.search.thStatus')}</Th>
                        <Th>{t('dashboard.search.thAssignee')}</Th>
                        <Th align="right">{t('dashboard.search.thDue')}</Th>
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
                            {Object.keys(params).length ? t('dashboard.search.emptyFiltered') : t('dashboard.search.empty')}
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
                                    {task.assignee?.name ?? <span className="text-gray-400">{t('dashboard.search.unassigned')}</span>}
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