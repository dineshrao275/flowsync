import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Spinner from '../ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { HRMS_MODULE_META, HRMS_MODULE_ROUTES } from '../../utils/hrmsModules';
import { employeeUrl, projectUrl, taskUrl, workspaceUrl } from '../../utils/deepLinks';

const groupDefs = [
    {
        key: 'tasks',
        label: 'Tasks',
        icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11',
    },
    {
        key: 'projects',
        label: 'Projects',
        icon: 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
    },
    {
        key: 'workspaces',
        label: 'Workspaces',
        icon: 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z',
    },
    {
        key: 'users',
        label: 'People',
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m4-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 2a3 3 0 10-3-3',
    },
    {
        key: 'hrms',
        label: 'HR',
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m4-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 2a3 3 0 10-3-3',
    },
];

function subtitleFor(group, item) {
    if (group === 'tasks') return `${item.project?.key ?? ''} · ${item.project?.name ?? ''}`;
    if (group === 'projects') return `${item.key ?? ''} · ${item.workspace ?? ''}`;
    if (group === 'workspaces') return `${item.tenant ?? ''} · ${item.projects_count ?? 0} projects`;
    if (group === 'users') return `${item.email ?? ''} ${item.tenant ? `· ${item.tenant}` : ''}`;
    if (group === 'hrms') return item.to ? 'Go to section' : `${item.employee_code ?? ''}`;
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
function shortcutRows(query, modules = []) {
    const q = query.trim().toLowerCase();
    const enabled = new Set(modules);

    return Object.entries(HRMS_MODULE_ROUTES)
        .filter(([key]) => enabled.has(key))
        .filter(([key]) => (HRMS_MODULE_META[key]?.label ?? '').toLowerCase().includes(q))
        .slice(0, 5)
        .map(([key]) => ({ id: `shortcut-${key}`, name: `Go to ${HRMS_MODULE_META[key].label}`, to: HRMS_MODULE_ROUTES[key] }));
}

export default function CommandPalette({ open, onClose }) {
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
            hrms: [...(results.employees ?? []), ...shortcutRows(query, user?.modules ?? [])],
        };

        return groupDefs
            .map((def) => ({ ...def, items: combined[def.key] ?? [] }))
            .filter((group) => group.items.length > 0);
    }, [results, query, user]);

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
            className="fixed inset-0 z-[80] bg-stone-900/40 backdrop-blur-md dark:bg-black/60"
            onClick={onClose}
            onKeyDown={(e) => e.key === 'Escape' && onClose()}
            role="presentation"
        >
            <div className="mx-auto mt-[8vh] w-full max-w-xl px-4" onClick={(e) => e.stopPropagation()}>
                <div className="overflow-hidden rounded-2xl border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--card-bg)] dark:bg-[#182030] shadow-popover animate-scale-in">
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
                                placeholder="#{A8A29E} dark:placeholder-[#64748B]"
                                className="h-12 flex-1 bg-transparent text-[#1C1917] dark:text-[#F8FAFC] outline-none placeholder:text-[#A8A29E] dark:placeholder-[#64748B]"
                                aria-label="Global search"
                            />
                        <kbd className="shrink-0 rounded border border-[var(--border-hairline)] dark:border-[#2F3A4C] bg-[var(--surface-elevated)] dark:bg-[#1E2638] text-[#78716C] dark:text-[#94A3B8]">
                            ESC
                        </kbd>
                    </div>

                    <div className="max-h-[55vh] overflow-y-auto p-2">
                        {query.trim().length < 2 ? (
                            <p className="px-3 py-10 text-center text-sm text-gray-400">
                                Type at least 2 characters to search.
                            </p>
                        ) : loading ? (
                            <div className="flex justify-center py-10">
                                <Spinner />
                            </div>
                        ) : groups.length === 0 ? (
                            <p className="px-3 py-10 text-center text-sm text-gray-400">
                                No results for &ldquo;{query.trim()}&rdquo;.
                            </p>
                        ) : (
                            groups.map((group) => {
                                const rows = group.items.map((item, index) => {
                                    const flatIndex = running + index;
                                    const isActive = flatIndex === active;
                                    return (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onMouseEnter={() => setActive(flatIndex)}
                                            onClick={() => openIndex(flatIndex)}
                                            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition ${
                                                isActive ? 'bg-[var(--accent-soft)] dark:bg-[#232B3A] text-[var(--accent-soft-text)] dark:text-[#F8FAFC]' : 'hover:bg-[var(--surface-elevated)] dark:hover:bg-[#1E2638]'}
                                            }`}
                                        >
                                            <svg
                                                className={`h-4 w-4 shrink-0 ${
                                                    isActive ? 'text-[var(--accent-soft-text)] dark:text-[#F8FAFC]' : 'text-gray-400'
                                                }`}
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.8"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <path d={group.icon} />
                                            </svg>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-[#1C1917] dark:text-[#F8FAFC]">
                                                    {group.key === 'tasks' || group.key === 'projects'
                                                        ? `${item.key} · ${item.title ?? item.name}`
                                                        : item.name}
                                                </span>
                                                <span className="block truncate text-xs text-[#78716C] dark:text-[#94A3B8]">
                                                    {subtitleFor(group.key, item)}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                });
                                running += group.items.length;

                                return (
                                    <div key={group.key} className="mb-1 last:mb-0">
                                        <p className="px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wider text-[#78716C] dark:text-[#64748B]">
                                            {group.label}
                                        </p>
                                        {rows}
                                    </div>
                                );
                            })
                        )}
                    </div>

                    <div className="border-t border-[var(--border-hairline)] dark:border-[#2F3A4C] text-[#78716C] dark:text-[#64748B]">
                        ↑↓ navigate &middot; ↵ select &middot; esc close
                    </div>
                </div>
            </div>
        </div>
    );
}