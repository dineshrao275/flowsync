import { fieldClassCompact } from '../ui/fieldStyles';
import Input from '../ui/Input';

export default function FiltersBar({ filters, options, onChange }) {
    function set(key, value) {
        onChange({ ...filters, [key]: value || null });
    }

    function clear() {
        onChange({});
    }

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="w-56">
                <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Search</label>
                <input
                    type="text"
                    placeholder="Title, key, description…"
                    value={filters.q || ''}
                    onChange={(e) => set('q', e.target.value)}
                    className={fieldClassCompact}
                />
            </div>
            <div>
                <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Status</label>
                <select className={fieldClassCompact} value={filters.status_id || ''} onChange={(e) => set('status_id', e.target.value)}>
                    <option value="">All statuses</option>
                    {options.statuses.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
            </div>
            <div>
                <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Priority</label>
                <select className={fieldClassCompact} value={filters.priority_id || ''} onChange={(e) => set('priority_id', e.target.value)}>
                    <option value="">All priorities</option>
                    {options.priorities.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
            </div>
            <div>
                <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Assignee</label>
                <select className={fieldClassCompact} value={filters.assignee_id || ''} onChange={(e) => set('assignee_id', e.target.value)}>
                    <option value="">Everyone</option>
                    {options.assignees.map((u) => (
                        <option key={u.id} value={u.id}>
                            {u.name}
                        </option>
                    ))}
                </select>
            </div>
            {options.labels?.length > 0 && (
                <div>
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Label</label>
                    <select className={fieldClassCompact} value={filters.label_id || ''} onChange={(e) => set('label_id', e.target.value)}>
                        <option value="">Any label</option>
                        {options.labels.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {options.issue_types?.length > 0 && (
                <div>
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Type</label>
                    <select className={fieldClassCompact} value={filters.issue_type_id || ''} onChange={(e) => set('issue_type_id', e.target.value)}>
                        <option value="">All types</option>
                        {options.issue_types.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {options.versions?.length > 0 && (
                <div>
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Version</label>
                    <select className={fieldClassCompact} value={filters.version_id || ''} onChange={(e) => set('version_id', e.target.value)}>
                        <option value="">All versions</option>
                        {options.versions.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {options.components?.length > 0 && (
                <div>
                    <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Component</label>
                    <select className={fieldClassCompact} value={filters.component_id || ''} onChange={(e) => set('component_id', e.target.value)}>
                        <option value="">All components</option>
                        {options.components.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <Input
                label="Due from"
                labelClassName="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500"
                type="date"
                compact
                value={filters.due_from || ''}
                onChange={(e) => set('due_from', e.target.value)}
            />
            <Input
                label="Due to"
                labelClassName="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500"
                type="date"
                compact
                value={filters.due_to || ''}
                onChange={(e) => set('due_to', e.target.value)}
            />
            <button
                type="button"
                onClick={clear}
                className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 shadow-sm transition hover:bg-gray-50"
            >
                Clear
            </button>
        </div>
    );
}