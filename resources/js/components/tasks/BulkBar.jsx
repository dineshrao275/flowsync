import { useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import { fieldClassCompact } from '../ui/fieldStyles';

const ACTIONS = [
    { key: 'transition', label: 'Move to status' },
    { key: 'assign', label: 'Assign' },
    { key: 'priority', label: 'Set priority' },
    { key: 'due', label: 'Set due date' },
    { key: 'label_add', label: 'Add label' },
    { key: 'label_remove', label: 'Remove label' },
];

/** Bulk edit toolbar for the list view (P4.6): one action over the ticked tasks, per-task results reported back. */
export default function BulkBar({ projectId, ids, options, canEdit, onClear, onDone }) {
    const toast = useToast();
    const [action, setAction] = useState('transition');
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState([]);

    function payload() {
        const base = { task_ids: ids };
        switch (action) {
            case 'transition': return { ...base, action: 'transition', status_id: Number(value) };
            case 'assign': return { ...base, action: 'assign', assignee_id: value ? Number(value) : null };
            case 'priority': return { ...base, action: 'update', priority_id: Number(value) };
            case 'due': return { ...base, action: 'update', due_date: value || null };
            case 'label_add': return { ...base, action: 'update', labels_add: [Number(value)] };
            default: return { ...base, action: 'update', labels_remove: [Number(value)] };
        }
    }

    async function apply() {
        setBusy(true);
        setFailed([]);
        try {
            const { data } = await api.post(`/projects/${projectId}/tasks/bulk`, payload());
            setFailed(data.failed || []);
            toast[data.failed?.length ? 'warning' : 'success'](`${data.updated.length} updated${data.failed?.length ? `, ${data.failed.length} skipped` : ''}.`);
            onDone();
        } catch (e) {
            toast.error(fieldErrors(e).fields || fieldErrors(e).status_id || e.response?.data?.message || 'Bulk update failed.');
        } finally {
            setBusy(false);
        }
    }

    const choices = {
        transition: options.statuses?.map((s) => [s.id, s.name]),
        assign: [['', 'Unassigned'], ...(options.assignees || []).map((u) => [u.id, u.name])],
        priority: options.priorities?.map((p) => [p.id, p.name]),
        label_add: options.labels?.map((l) => [l.id, l.name]),
        label_remove: options.labels?.map((l) => [l.id, l.name]),
    }[action];

    return (
        <div className="space-y-2 rounded-xl border border-indigo-200 bg-indigo-50/60 p-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium text-indigo-900">{ids.length} selected</span>
                <select className={fieldClassCompact} value={action} onChange={(e) => { setAction(e.target.value); setValue(''); }}>
                    {ACTIONS.filter((a) => canEdit || a.key === 'transition').map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
                </select>
                {choices ? (
                    <select className={fieldClassCompact} value={value} onChange={(e) => setValue(e.target.value)}>
                        {action !== 'assign' && <option value="">Choose…</option>}
                        {choices.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                    </select>
                ) : (
                    <input type="date" className={fieldClassCompact} value={value} onChange={(e) => setValue(e.target.value)} />
                )}
                <Button type="button" onClick={apply} loading={busy} disabled={action !== 'assign' && action !== 'due' && !value}>Apply</Button>
                <button type="button" onClick={onClear} className="text-xs text-gray-500 hover:underline">Clear</button>
            </div>
            {failed.length > 0 && (
                <ul className="text-xs text-amber-800">
                    {failed.map((f) => <li key={f.id}>{f.key ?? `#${f.id}`}: {f.reason}</li>)}
                </ul>
            )}
        </div>
    );
}
