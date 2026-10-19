import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import Card from '../ui/Card';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Alert from '../ui/Alert';

/** SLA policies: "tasks (of this priority) are resolved within N hours of being created". Breaches raise task.sla_breached. */
export default function SlaPanel({ projectId, canManage }) {
    const { t } = useTranslation();
    const { hasModule } = useAuth();
    const [policies, setPolicies] = useState(null);
    const [priorities, setPriorities] = useState([]);
    const [form, setForm] = useState({ name: '', target_hours: '', priority_id: '' });
    const [errors, setErrors] = useState({});

    const load = useCallback(() => {
        api.get(`/projects/${projectId}/sla-policies`).then(({ data }) => setPolicies(data.policies)).catch(() => setPolicies([]));
    }, [projectId]);

    useEffect(() => {
        if (!hasModule('automation')) return;
        load();
        api.get(`/projects/${projectId}/tasks`, { params: { per_page: 1 } }).then(({ data }) => setPriorities(data.filters?.priorities || [])).catch(() => {});
    }, [projectId, hasModule, load]);

    if (!hasModule('automation')) return null;

    async function add(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post(`/projects/${projectId}/sla-policies`, { name: form.name, target_hours: Number(form.target_hours), priority_id: form.priority_id ? Number(form.priority_id) : null });
            setForm({ name: '', target_hours: '', priority_id: '' });
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(id) {
        await api.delete(`/projects/${projectId}/sla-policies/${id}`);
        load();
    }

    const priorityName = (id) => priorities.find((p) => p.id === id)?.name;

    return (
        <Card title={t('tms.sla.title')} subtitle={t('tms.sla.subtitle')}>
            {policies && policies.length === 0 && <p className="mb-3 text-sm text-gray-500">{t('tms.sla.empty')}</p>}
            <ul className="divide-y divide-gray-100 text-sm">
                {(policies || []).map((p) => (
                    <li key={p.id} className="flex items-center justify-between gap-2 py-2">
                        <span>{p.name} · {t('tms.sla.within', { hours: p.target_hours })}{p.priority_id ? ` · ${priorityName(p.priority_id) || t('tms.sla.onePriority')}` : ` · ${t('tms.sla.allPriorities')}`}</span>
                        {canManage && <Button size="sm" variant="danger" onClick={() => remove(p.id)}>{t('tms.sla.remove')}</Button>}
                    </li>
                ))}
            </ul>
            {canManage && (
                <form onSubmit={add} className="mt-4 grid gap-3 sm:grid-cols-4">
                    <Input label={t('tms.sla.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required />
                    <Input label={t('tms.sla.hours')} type="number" min="1" value={form.target_hours} onChange={(e) => setForm({ ...form, target_hours: e.target.value })} error={errors.target_hours} required />
                    <div>
                        <label htmlFor="sla-priority" className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.sla.priority')}</label>
                        <select id="sla-priority" className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value={form.priority_id} onChange={(e) => setForm({ ...form, priority_id: e.target.value })}>
                            <option value="">{t('tms.sla.allPriorities')}</option>
                            {priorities.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    </div>
                    <div className="flex items-end"><Button type="submit">{t('tms.sla.add')}</Button></div>
                </form>
            )}
            {errors.form && <Alert>{errors.form}</Alert>}
        </Card>
    );
}
