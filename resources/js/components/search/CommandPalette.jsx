import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { CheckCircle, Folders, IdentificationBadge, Kanban, Users } from '@phosphor-icons/react';
import api from '../../services/api';
import ContentSkeleton from '../ui/ContentSkeleton';
import { useAuth } from '../../context/AuthContext';
import { HRMS_MODULE_META, HRMS_MODULE_ROUTES } from '../../utils/hrmsModules';
import { employeeUrl, projectUrl, taskUrl, workspaceUrl } from '../../utils/deepLinks';
import TaskBadge from '../ui/TaskBadge';

const groupDefs = [
    {
        key: 'tasks',
        icon: CheckCircle,
    },
    {
        key: 'projects',
        icon: Kanban,
    },
    {
        key: 'workspaces',
        icon: Folders,
    },
    {
        key: 'users',
        icon: Users,
    },
    {
        key: 'hrms',
        icon: IdentificationBadge,
    },
];

function subtitleFor(t, group, item) {
    if (group === 'tasks') return `${item.project?.key ?? ''} · ${item.project?.name ?? ''}`;
    if (group === 'projects') return `${item.key ?? ''} · ${item.workspace ?? ''}`;
    if (group === 'workspaces') {
        const count = item.projects_count ?? 0;
        return `${item.tenant ?? ''} · ${count === 1 ? t('shell.commandPalette.projectsCountOne') : t('shell.commandPalette.projectsCount', { count })}`;
    }
    if (group === 'users') return `${item.email ?? ''} ${item.tenant ? `· ${item.tenant}` : ''}`;
    if (group === 'hrms') return item.to ? t('shell.commandPalette.goToSection') : `${item.employee_code ?? ''}`;
    return '';
}

function hrefFor(group, item) {
    if (group === 'tasks') return taskUrl(item.project?.id, item.key);
    if (group === 'projects') return projectUrl(item.id);
    if (group === 'workspaces') return workspaceUrl(item.id);
    if (group === 'hrms') return item.to ?? employeeUrl(item.id);
    return null;
}

/**
 * Shortcut rows for the HR modules this tenant holds: employees come
 * from the search API, then one jump row per routed module matching the
 * query. Only routed modules qualify — a shortcut to a page the router
 * does not own is the dead-tile failure the shell test guards.
 */
function shortcutRows(t, query, modules = []) {
    const q = query.trim().toLowerCase();
    const enabled = new Set(modules);

    return Object.entries(HRMS_MODULE_ROUTES)
        .filter(([key]) => enabled.has(key))
        .filter(([key]) => (HRMS_MODULE_META[key]?.label ?? '').toLowerCase().includes(q))
        .slice(0, 5)
        .map(([key]) => ({ id: `shortcut-${key}`, name: t('shell.commandPalette.goTo', { label: HRMS_MODULE_META[key].label }), to: HRMS_MODULE_ROUTES[key] }));
}

export default function CommandPalette({ open, onClose }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState(null);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef(null);
    const navigate = useNavigate();
    const { user } = useAuth();

    useEffect(() => {
        if (!open) return;
        setQuery('');
        setResults(null);
        setLoading(false);
        setActive(0);
        const id = window.setTimeout(() => inputRef.current?.focus(), 30);
        return () => window.clearTimeout(id);
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const q = query.trim();
        if (q.length < 2) {
            setResults(null);
            setActive(0);
            return;
        }
        setLoading(true);
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            api.get('/search/global', { params: { q }, signal: controller.signal })
                .then(({ data }) => {
                    setResults(data.results);
                    setActive(0);
                })
                .catch(() => {
                    setResults({ tasks: [], projects: [], workspaces: [], users: [], employees: [] });
                })
                .finally(() => setLoading(false));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query]);

    const groups = useMemo(() => {
        if (results === null) return [];

        const combined = {
            ...results,
            hrms: [...(results.employees ?? []), ...shortcutRows(t, query, user?.modules ?? [])],
        };

        return groupDefs
            .map((def) => ({ ...def, items: combined[def.key] ?? [] }))
            .filter((group) => group.items.length > 0);
    }, [results, query, user, t]);

    const flatLength = useMemo(
        () => groups.reduce((sum, group) => sum + group.items.length, 0),
        [groups],
    );

    function openIndex(index) {
        let cursor = 0;
        for (const group of groups) {
            if (index < cursor + group.items.length) {
                const item = group.items[index - cursor];
                const href = hrefFor(group.key, item);
                onClose();
                if (href) navigate(href);
                return;
            }
            cursor += group.items.length;
        }
    }

    function onKeyDown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(a + 1, Math.max(flatLength - 1, 0)));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            openIndex(active);
        }
    }

    if (!open) return null;

    let running = 0;

    return (
        <div
            className="fixed inset-0 z-[80] bg-gray-900/40 animate-backdrop-in"
            onClick={onClose}
            onKeyDown={(e) => e.key === 'Escape' && onClose()}
            role="presentation"
        >
            <div className="mx-auto mt-[8vh] w-full max-w-xl px-4" onClick={(e) => e.stopPropagation()}>
                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-popover animate-scale-in">
                    <div className="flex items-center gap-3 border-b border-gray-100 px-4">
                        <svg
                            className="h-5 w-5 shrink-0 text-gray-400"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                        </svg>
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder={t('shell.commandPalette.placeholder')}
                            className="h-12 flex-1 bg-transparent text-sm text-gray-900 outline-none placeholder:text-gray-400"
                            aria-label={t('shell.commandPalette.ariaLabel')}
                        />
                        <kbd className="shrink-0 rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-400">
                            {t('shell.commandPalette.escLabel')}
                        </kbd>
                    </div>

                    <div className="max-h-[55vh] overflow-y-auto p-2">
                        {query.trim().length < 2 ? (
                            <p className="px-3 py-10 text-center text-sm text-gray-400">
                                {t('shell.commandPalette.hint')}
                            </p>
                        ) : loading ? (
                            <ContentSkeleton size="md" />
                        ) : groups.length === 0 ? (
                            <p className="px-3 py-10 text-center text-sm text-gray-400">
                                {t('shell.commandPalette.noResults', { query: query.trim() })}
                            </p>
                        ) : (
                            groups.map((group) => {
                                const rows = group.items.map((item, index) => {
                                    const flatIndex = running + index;
                                    const isActive = flatIndex === active;
                                    const GroupIcon = group.icon;
                                    return (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onMouseEnter={() => setActive(flatIndex)}
                                            onClick={() => openIndex(flatIndex)}
                                            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition ${
                                                isActive ? 'bg-[var(--accent-soft)]' : 'hover:bg-gray-50'
                                            }`}
                                        >
                                            <GroupIcon
                                                size={16}
                                                aria-hidden="true"
                                                className={`shrink-0 ${
                                                    isActive ? 'text-[var(--accent-soft-text)]' : 'text-gray-400'
                                                }`}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-gray-800">
                                                    {group.key === 'tasks' ? (
                                                        <span className="flex items-center gap-2 truncate">
                                                            <TaskBadge taskKey={item.key} projectId={item.project?.id} />
                                                            <span className="truncate">{item.title}</span>
                                                        </span>
                                                    ) : group.key === 'projects' ? (
                                                        `${item.key} · ${item.name}`
                                                    ) : (
                                                        item.name
                                                    )}
                                                </span>
                                                <span className="block truncate text-xs text-gray-400">
                                                    {subtitleFor(t, group.key, item)}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                });
                                running += group.items.length;

                                return (
                                    <div key={group.key} className="mb-1 last:mb-0">
                                        <p className="px-3 pb-1 pt-2 text-xs font-semibold text-gray-500">
                                            {t(`shell.commandPalette.groups.${group.key}`)}
                                        </p>
                                        {rows}
                                    </div>
                                );
                            })
                        )}
                    </div>

                    <div className="border-t border-gray-100 px-4 py-2 text-xs text-gray-400">
                        {t('shell.commandPalette.footer')}
                    </div>
                </div>
            </div>
        </div>
    );
}