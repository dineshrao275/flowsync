import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import { fieldClassCompact } from '../ui/fieldStyles';

/** Bulk edit toolbar for the list view (P4.6): one action over the ticked tasks, per-task results reported back. */
export default function BulkBar({ projectId, ids, options, canEdit, onClear, onDone }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [action, setAction] = useState('transition');
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState([]);

    const ACTIONS = [
        { key: 'transition', label: t('tms.bulk.transition') },
        { key: 'assign', label: t('tms.bulk.assign') },
        { key: 'priority', label: t('tms.bulk.priority') },
        { key: 'due', label: t('tms.bulk.due') },
        { key: 'label_add', label: t('tms.bulk.labelAdd') },
        { key: 'label_remove', label: t('tms.bulk.labelRemove') },
    ];

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
            toast[data.failed?.length ? 'warning' : 'success'](
                data.failed?.length
                    ? t('tms.bulk.updatedSkipped', { updated: data.updated.length, skipped: data.failed.length })
                    : t('tms.bulk.updated', { count: data.updated.length }),
            );
            onDone();
        } catch (e) {
            toast.error(fieldErrors(e).fields || fieldErrors(e).status_id || e.response?.data?.message || t('tms.bulk.failed'));
        } finally {
            setBusy(false);
        }
    }

    const choices = {
        transition: options.statuses?.map((s) => [s.id, s.name]),
        assign: [['', t('tms.bulk.unassigned')], ...(options.assignees || []).map((u) => [u.id, u.name])],
        priority: options.priorities?.map((p) => [p.id, p.name]),
        label_add: options.labels?.map((l) => [l.id, l.name]),
        label_remove: options.labels?.map((l) => [l.id, l.name]),
    }[action];

    return (
        <div className="space-y-2 rounded-xl border border-[var(--accent-ring)] bg-[var(--accent-soft)] p-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium text-[var(--accent-soft-text)]">{t('tms.bulk.selected', { count: ids.length })}</span>
                <select className={fieldClassCompact} value={action} onChange={(e) => { setAction(e.target.value); setValue(''); }}>
                    {ACTIONS.filter((a) => canEdit || a.key === 'transition').map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
                </select>
                {choices ? (
                    <select className={fieldClassCompact} value={value} onChange={(e) => setValue(e.target.value)}>
                        {action !== 'assign' && <option value="">{t('tms.bulk.choose')}</option>}
                        {choices.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                    </select>
                ) : (
                    <input type="date" className={fieldClassCompact} value={value} onChange={(e) => setValue(e.target.value)} />
                )}
                <Button type="button" onClick={apply} loading={busy} disabled={action !== 'assign' && action !== 'due' && !value}>{t('tms.bulk.apply')}</Button>
                <button type="button" onClick={onClear} className="text-xs text-gray-500 hover:underline">{t('tms.bulk.clear')}</button>
            </div>
            {failed.length > 0 && (
                <ul className="text-xs text-amber-800">
                    {failed.map((f) => <li key={f.id}>{f.key ?? `#${f.id}`}: {f.reason}</li>)}
                </ul>
            )}
        </div>
    );
}
