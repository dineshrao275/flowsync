function ChevronIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
            <path d="m6 9 6 6 6-6" />
        </svg>
    );
}

function SearchIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="11" cy="11" r="7" />
            <path d="m21 21-4.3-4.3" />
        </svg>
    );
}

function XIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M18 6 6 18M6 6l12 12" />
        </svg>
    );
}

/**
 * FiltersBar — screen 12's single toolbar: a `#F8F9FC` search area on the left
 * and `h23 rx8` filter chips (`#F8F9FC`, muted) inline, all inside one white
 * `rx8.5` bordered bar.
 */
function Chip({ value, onChange, children, active }) {
    return (
        <div className="relative shrink-0">
            <select
                value={value}
                onChange={onChange}
                className={`h-[23px] w-full cursor-pointer appearance-none rounded-[8px] bg-[var(--field-bg)] pl-2.5 pr-7 text-[11px] font-medium outline-none transition hover:bg-[var(--accent-soft)] ${
                    active ? 'text-ink' : 'text-muted'
                }`}
            >
                {children}
            </select>
            <ChevronIcon className="pointer-events-none absolute right-2 top-1/2 h-3 w-3 -translate-y-1/2 text-faint" />
        </div>
    );
}

export default function FiltersBar({ filters, options, onChange }) {
    function set(key, value) {
        onChange({ ...filters, [key]: value || null });
    }

    function clear() {
        onChange({});
    }

    const hasFilters = Object.values(filters || {}).some((v) => v !== null && v !== '' && v !== undefined);

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-[8.5px] border border-[var(--border-hairline)] bg-[var(--card-bg)] px-3 py-2">
            <div className="flex h-[23px] min-w-[220px] flex-1 items-center gap-2">
                <SearchIcon className="h-3.5 w-3.5 shrink-0 text-faint" />
                <input
                    type="text"
                    placeholder="Search issues…"
                    value={filters.q || ''}
                    onChange={(e) => set('q', e.target.value)}
                    className="w-full bg-transparent text-[12px] text-ink outline-none placeholder:text-faint"
                />
            </div>

            <div className="hidden h-5 w-px shrink-0 bg-[var(--border-hairline)] sm:block" />

            <Chip value={filters.status_id || ''} active={!!filters.status_id} onChange={(e) => set('status_id', e.target.value)}>
                <option value="">Status</option>
                {options.statuses.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                ))}
            </Chip>

            <Chip value={filters.priority_id || ''} active={!!filters.priority_id} onChange={(e) => set('priority_id', e.target.value)}>
                <option value="">Priority</option>
                {options.priorities.map((p) => (
                    <option key={p.id} value={p.id}>{p.name}</option>
                ))}
            </Chip>

            <Chip value={filters.assignee_id || ''} active={!!filters.assignee_id} onChange={(e) => set('assignee_id', e.target.value)}>
                <option value="">Assignee</option>
                {options.assignees.map((u) => (
                    <option key={u.id} value={u.id}>{u.name}</option>
                ))}
            </Chip>

            {options.sprints && (
                <Chip value={filters.sprint || ''} active={!!filters.sprint} onChange={(e) => set('sprint', e.target.value)}>
                    <option value="">Sprint</option>
                    <option value="active">Active sprint</option>
                    <option value="none">Backlog</option>
                </Chip>
            )}

            {options.labels?.length > 0 && (
                <Chip value={filters.label_id || ''} active={!!filters.label_id} onChange={(e) => set('label_id', e.target.value)}>
                    <option value="">Labels</option>
                    {options.labels.map((l) => (
                        <option key={l.id} value={l.id}>{l.name}</option>
                    ))}
                </Chip>
            )}

            {options.issue_types?.length > 0 && (
                <Chip value={filters.issue_type_id || ''} active={!!filters.issue_type_id} onChange={(e) => set('issue_type_id', e.target.value)}>
                    <option value="">Type</option>
                    {options.issue_types.map((t) => (
                        <option key={t.id} value={t.id}>{t.name}</option>
                    ))}
                </Chip>
            )}

            {options.versions?.length > 0 && (
                <Chip value={filters.version_id || ''} active={!!filters.version_id} onChange={(e) => set('version_id', e.target.value)}>
                    <option value="">Version</option>
                    {options.versions.map((v) => (
                        <option key={v.id} value={v.id}>{v.name}</option>
                    ))}
                </Chip>
            )}

            {options.components?.length > 0 && (
                <Chip value={filters.component_id || ''} active={!!filters.component_id} onChange={(e) => set('component_id', e.target.value)}>
                    <option value="">Component</option>
                    {options.components.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </Chip>
            )}

            <input
                type="date"
                aria-label="Due from"
                value={filters.due_from || ''}
                onChange={(e) => set('due_from', e.target.value)}
                className="h-[23px] shrink-0 rounded-[8px] bg-[var(--field-bg)] px-2 text-[11px] text-muted outline-none"
            />

            <input
                type="date"
                aria-label="Due to"
                value={filters.due_to || ''}
                onChange={(e) => set('due_to', e.target.value)}
                className="h-[23px] shrink-0 rounded-[8px] bg-[var(--field-bg)] px-2 text-[11px] text-muted outline-none"
            />

            {hasFilters && (
                <button
                    type="button"
                    onClick={clear}
                    title="Clear filters"
                    className="flex h-[23px] w-[23px] shrink-0 items-center justify-center rounded-[8px] text-faint transition hover:bg-[var(--field-bg)] hover:text-ink"
                >
                    <XIcon className="h-3.5 w-3.5" />
                </button>
            )}
        </div>
    );
}
