import { fieldClassCompact } from '../ui/fieldStyles';

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
            <div>
                <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Due from</label>
                <input
                    type="date"
                    value={filters.due_from || ''}
                    onChange={(e) => set('due_from', e.target.value)}
                    className={fieldClassCompact}
                />
            </div>
            <div>
                <label className="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Due to</label>
                <input
                    type="date"
                    value={filters.due_to || ''}
                    onChange={(e) => set('due_to', e.target.value)}
                    className={fieldClassCompact}
                />
            </div>
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