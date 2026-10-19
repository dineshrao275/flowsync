import { useEffect, useState } from 'react';
import ListSavedFilters from '../components/ui/ListSavedFilters';
import UserChip from '../components/ui/UserChip';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Pagination from '../components/ui/Pagination';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { fieldClass } from '../components/ui/fieldStyles';
import usePageTitle from '../hooks/usePageTitle';
import useUrlState from '../hooks/useUrlState';
import { useResource } from '../hooks/useResource';
import ErrorState from '../components/ui/ErrorState';
import { projectUrl } from '../utils/deepLinks';

const FILTER_KEYS = ['q', 'workspace_id', 'project_id', 'status_id', 'priority_id', 'assignee_id', 'label_id', 'due_from', 'due_to'];
const URL_DEFAULTS = { ...Object.fromEntries(FILTER_KEYS.map((key) => [key, ''])), page: '1' };
const EMPTY_OPTIONS = { statuses: [], priorities: [], assignees: [], projects: [], workspaces: [], labels: [] };

export default function Search() {
    const { t } = useTranslation();
    usePageTitle(t('dashboard.search.pageTitle'));
    const setCrumbs = useSetCrumbs();

    // The applied filters + page live in the URL (shareable, refresh-safe,
    // back/forward aware); `filters` is only the draft being typed.
    const [url, updateUrl, resetUrl] = useUrlState(URL_DEFAULTS);
    const applied = Object.fromEntries(FILTER_KEYS.filter((key) => url[key] !== '').map((key) => [key, url[key]]));
    const page = Number(url.page) || 1;
    const [filters, setFilters] = useState(applied);
    const [options, setOptions] = useState(EMPTY_OPTIONS);

    const { data, loading, error, reload } = useResource(
        (signal) => api.get('/search/tasks', { params: { ...applied, page, per_page: 25 }, signal }).then(({ data: response }) => response),
        [JSON.stringify(applied), page],
    );

    useEffect(() => {
        setCrumbs([{ label: t('dashboard.search.pageTitle') }]);
    }, [setCrumbs, t]);

    // Filter option lists come back with each response; keep the last set so the
    // selects don't empty while the next page loads.
    useEffect(() => {
        if (data?.filters) setOptions(data.filters);
    }, [data]);

    // Back/forward (or a pasted link) changes the URL under the form.
    useEffect(() => {
        setFilters(applied);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(applied)]);

    function submit(e) {
        e.preventDefault();
        const clean = Object.fromEntries(FILTER_KEYS.map((key) => [key, filters[key] ?? '']));
        updateUrl(clean);
    }

    function reset() {
        setFilters({});
        resetUrl();
    }

    const params = applied;

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

            {error && data == null && <ErrorState title={t('dashboard.search.loadFailed')} onRetry={reload} />}

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
                <ListSavedFilters
                    page="task-search"
                    value={filters}
                    onApply={(saved) => {
                        setFilters(saved);
                        updateUrl(Object.fromEntries(FILTER_KEYS.map((key) => [key, saved[key] ?? ''])));
                    }}
                />
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
                    {loading && data == null ? (
                        <tr>
                            <Td colSpan={7} className="py-12 text-center">
                                <ContentSkeleton size="sm" />
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
                                    {task.assignee ? <UserChip user={task.assignee} /> : <span className="text-gray-400">{t('dashboard.search.unassigned')}</span>}
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
                    onChange={(p) => updateUrl({ page: String(p) })}
                />
            )}
        </div>
    );
}