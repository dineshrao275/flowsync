import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Card from '../ui/Card';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../ui/ConfirmDialog';
import Timestamp from '../ui/Timestamp';

const blankRule = { name: '', trigger: 'task.completed', is_active: true, conditions: [], actions: [{ type: 'add_comment', text: '' }] };
const sel = 'rounded-lg border border-gray-300 px-2 py-1.5 text-sm';

/** Project automation: list, build and review rules ("when … and … then …"). */
export default function AutomationPanel({ projectId, members, statuses, labels, priorities }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [catalog, setCatalog] = useState(null);
    const [rules, setRules] = useState(null);
    const [editing, setEditing] = useState(null); // null | {id?, ...rule}
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [runs, setRuns] = useState({ id: null, items: [] });

    const load = useCallback(() => api.get(`/projects/${projectId}/automations`).then(({ data }) => setRules(data.rules)).catch(() => setRules([])), [projectId]);
    useEffect(() => { api.get('/automation/catalog').then(({ data }) => setCatalog(data)); load(); }, [load]);

    function setAction(i, patch) { setEditing((r) => ({ ...r, actions: r.actions.map((a, j) => (j === i ? { ...a, ...patch } : a)) })); }
    function setCondition(i, patch) { setEditing((r) => ({ ...r, conditions: r.conditions.map((c, j) => (j === i ? { ...c, ...patch } : c)) })); }

    async function save(e) {
        e.preventDefault();
        setSaving(true); setErrors({});
        try {
            const body = { name: editing.name, trigger: editing.trigger, is_active: editing.is_active, conditions: editing.conditions, actions: editing.actions };
            if (editing.id) await api.put(`/projects/${projectId}/automations/${editing.id}`, body);
            else await api.post(`/projects/${projectId}/automations`, body);
            toast.success(t('tms.automation.saved'));
            setEditing(null); load();
        } catch (err) { setErrors(fieldErrors(err)); } finally { setSaving(false); }
    }

    async function showRuns(id) {
        if (runs.id === id) { setRuns({ id: null, items: [] }); return; }
        const { data } = await api.get(`/projects/${projectId}/automations/${id}/runs`);
        setRuns({ id, items: data.runs });
    }

    if (!catalog || !rules) return <div className="flex justify-center py-10"><Spinner /></div>;

    function valueInput(c, i) {
        if (['is_empty', 'is_not_empty', 'is_overdue'].includes(c.op)) return null;
        if (c.field === 'status') return <select className={sel} value={c.value ?? ''} onChange={(e) => setCondition(i, { value: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{statuses.map((s) => <option key={s.id} value={s.slug}>{s.name}</option>)}</select>;
        if (c.field === 'status_category') return <select className={sel} value={c.value ?? ''} onChange={(e) => setCondition(i, { value: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{['todo', 'in_progress', 'done'].map((v) => <option key={v}>{v}</option>)}</select>;
        if (c.field === 'priority') return <select className={sel} value={c.value ?? ''} onChange={(e) => setCondition(i, { value: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{priorities.map((p) => <option key={p.id} value={p.slug}>{p.name}</option>)}</select>;
        if (['assignee', 'reporter'].includes(c.field)) return <select className={sel} value={c.value ?? ''} onChange={(e) => setCondition(i, { value: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select>;
        if (c.field === 'label') return <select className={sel} value={c.value ?? ''} onChange={(e) => setCondition(i, { value: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{labels.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</select>;
        return <input className={`${sel} w-40`} value={c.value ?? ''} onChange={(e) => setCondition(i, { value: e.target.value })} />;
    }

    function actionInputs(a, i) {
        switch (a.type) {
            case 'set_assignee': return <select className={sel} value={a.user ?? ''} onChange={(e) => setAction(i, { user: e.target.value })}><option value="">{t('tms.automation.choose')}</option><option value="reporter">{t('tms.automation.reporter')}</option><option value="lead">{t('tms.automation.lead')}</option><option value="unassign">{t('tms.automation.unassign')}</option>{members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select>;
            case 'set_priority': return <select className={sel} value={a.priority ?? ''} onChange={(e) => setAction(i, { priority: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{priorities.map((p) => <option key={p.id} value={p.slug}>{p.name}</option>)}</select>;
            case 'move_to_status': return <select className={sel} value={a.status ?? ''} onChange={(e) => setAction(i, { status: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{statuses.map((s) => <option key={s.id} value={s.slug}>{s.name}</option>)}</select>;
            case 'add_label': return <select className={sel} value={a.label ?? ''} onChange={(e) => setAction(i, { label: e.target.value })}><option value="">{t('tms.automation.choose')}</option>{labels.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</select>;
            case 'add_comment': return <input className={`${sel} min-w-72 flex-1`} placeholder="Comment text — {{key}} {{title}} {{assignee}}" value={a.text ?? ''} onChange={(e) => setAction(i, { text: e.target.value })} />;
            case 'notify': return (
                <>
                    <select className={sel} value={(a.to || ['assignee'])[0]} onChange={(e) => setAction(i, { to: [e.target.value] })}><option value="assignee">{t('tms.automation.assignee')}</option><option value="reporter">{t('tms.automation.reporter')}</option><option value="lead">{t('tms.automation.lead')}</option>{members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select>
                    <input className={`${sel} min-w-64 flex-1`} placeholder={t('tms.automation.messagePlaceholder')} value={a.message ?? ''} onChange={(e) => setAction(i, { message: e.target.value })} />
                </>
            );
            default: return null;
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <p className="text-sm text-gray-500">{t('tms.automation.intro')}</p>
                <Button onClick={() => { setErrors({}); setEditing({ ...blankRule }); }}>{t('tms.automation.newRule')}</Button>
            </div>

            {editing && (
                <Card title={editing.id ? t('tms.automation.editRule') : t('tms.automation.newRule')}>
                    <form onSubmit={save} className="space-y-4">
                        <Input label={t('tms.automation.name')} name="name" required value={editing.name} onChange={(e) => setEditing((r) => ({ ...r, name: e.target.value }))} error={errors.name} />
                        <div>
                            <p className="mb-1 text-sm font-semibold text-gray-800">{t('tms.automation.when')}</p>
                            <select className={sel} value={editing.trigger} onChange={(e) => setEditing((r) => ({ ...r, trigger: e.target.value }))}>
                                {Object.entries(catalog.triggers).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                        </div>
                        <div>
                            <p className="mb-1 text-sm font-semibold text-gray-800">{t('tms.automation.conditions')}</p>
                            {editing.conditions.map((c, i) => (
                                <div key={i} className="mb-2 flex flex-wrap items-center gap-2">
                                    <select className={sel} value={c.field} onChange={(e) => setCondition(i, { field: e.target.value, op: catalog.fields[e.target.value].ops[0], value: '' })}>
                                        {Object.entries(catalog.fields).map(([k, f]) => <option key={k} value={k}>{f.label}</option>)}
                                    </select>
                                    <select className={sel} value={c.op} onChange={(e) => setCondition(i, { op: e.target.value })}>
                                        {catalog.fields[c.field].ops.map((o) => <option key={o} value={o}>{o.replace(/_/g, ' ')}</option>)}
                                    </select>
                                    {valueInput(c, i)}
                                    <button type="button" className="text-sm text-red-600" onClick={() => setEditing((r) => ({ ...r, conditions: r.conditions.filter((_, j) => j !== i) }))}>{t('tms.automation.remove')}</button>
                                    {errors[`conditions.${i}`] && <span className="text-sm text-red-600">{errors[`conditions.${i}`]}</span>}
                                </div>
                            ))}
                            <Button type="button" size="sm" variant="secondary" onClick={() => setEditing((r) => ({ ...r, conditions: [...r.conditions, { field: 'priority', op: 'is', value: '' }] }))}>{t('tms.automation.addCondition')}</Button>
                        </div>
                        <div>
                            <p className="mb-1 text-sm font-semibold text-gray-800">{t('tms.automation.then')}</p>
                            {editing.actions.map((a, i) => (
                                <div key={i} className="mb-2 flex flex-wrap items-center gap-2">
                                    <select className={sel} value={a.type} onChange={(e) => setEditing((r) => ({ ...r, actions: r.actions.map((x, j) => (j === i ? { type: e.target.value } : x)) }))}>
                                        {Object.entries(catalog.actions).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                                    </select>
                                    {actionInputs(a, i)}
                                    {editing.actions.length > 1 && <button type="button" className="text-sm text-red-600" onClick={() => setEditing((r) => ({ ...r, actions: r.actions.filter((_, j) => j !== i) }))}>{t('tms.automation.remove')}</button>}
                                    {errors[`actions.${i}`] && <span className="text-sm text-red-600">{errors[`actions.${i}`]}</span>}
                                </div>
                            ))}
                            <Button type="button" size="sm" variant="secondary" onClick={() => setEditing((r) => ({ ...r, actions: [...r.actions, { type: 'add_comment', text: '' }] }))}>{t('tms.automation.addAction')}</Button>
                            {errors.actions && <p className="mt-1 text-sm text-red-600">{errors.actions}</p>}
                        </div>
                        <Alert>{errors.form || errors.trigger}</Alert>
                        <div className="flex gap-2">
                            <Button type="button" variant="secondary" onClick={() => setEditing(null)}>{t('tms.automation.cancel')}</Button>
                            <Button type="submit" loading={saving}>{t('tms.automation.saveRule')}</Button>
                        </div>
                    </form>
                </Card>
            )}

            {rules.length === 0 && !editing ? <p className="py-8 text-center text-sm text-gray-400">{t('tms.automation.empty')}</p> : (
                <ul className="space-y-3">
                    {rules.map((r) => (
                        <li key={r.id} className="rounded-xl border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="font-semibold text-gray-900">{r.name}</p>
                                    <p className="text-xs text-gray-500">{t('tms.automation.summary', { trigger: catalog.triggers[r.trigger]?.toLowerCase(), conditions: r.conditions.length, actions: r.actions.map((a) => catalog.actions[a.type]).join(', ') })}</p>
                                    <p className="text-xs text-gray-400">{t('tms.automation.runs', { count: r.run_count })}{r.last_run_at ? <>{t('tms.automation.lastRun')} <Timestamp date={r.last_run_at} /></> : ''}</p>
                                </div>
                                <Badge>{r.is_active ? t('tms.automation.on') : t('tms.automation.off')}</Badge>
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <Button size="sm" variant="secondary" onClick={() => { setErrors({}); setEditing({ ...r }); }}>{t('tms.automation.edit')}</Button>
                                <Button size="sm" variant="secondary" onClick={() => api.put(`/projects/${projectId}/automations/${r.id}`, { name: r.name, trigger: r.trigger, conditions: r.conditions, actions: r.actions, is_active: !r.is_active }).then(load)}>{r.is_active ? t('tms.automation.turnOff') : t('tms.automation.turnOn')}</Button>
                                <Button size="sm" variant="secondary" onClick={() => showRuns(r.id)}>{runs.id === r.id ? t('tms.automation.hideRuns') : t('tms.automation.runLog')}</Button>
                                <Button size="sm" variant="danger" onClick={async () => { if (await confirm({ title: t('tms.automation.deleteTitle'), message: t('tms.automation.deleteMessage'), variant: 'danger' })) api.delete(`/projects/${projectId}/automations/${r.id}`).then(load); }}>{t('tms.automation.delete')}</Button>
                            </div>
                            {runs.id === r.id && (
                                <ul className="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-100 text-xs">
                                    {runs.items.length === 0 && <li className="px-3 py-3 text-center text-gray-400">{t('tms.automation.noRuns')}</li>}
                                    {runs.items.map((x) => (
                                        <li key={x.id} className="flex flex-wrap gap-2 px-3 py-2">
                                            <span className={x.status === 'success' ? 'text-emerald-700' : x.status === 'failed' ? 'text-red-700' : 'text-gray-500'}>{x.status}</span>
                                            <span className="text-gray-600">{x.summary}</span>
                                            <span className="ml-auto text-gray-400"><Timestamp date={x.created_at} /></span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
