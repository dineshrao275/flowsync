import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Switch from '../../components/ui/Switch';
import Spinner from '../../components/ui/Spinner';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const BLANK = { name: '', trigger: 'employee.created', project_id: '', is_active: true, tasks: [{ title: '', description: '', assignee: 'none', due_in_days: '' }] };

/** "When this HR event happens, create these tasks in this project." */
export default function HrAutomation() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.hrAutomation.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
    const [data, setData] = useState(null);
    const [projects, setProjects] = useState([]);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        api.get('/hrms/automation-rules').then(({ data: res }) => setData(res)).catch(() => setData({ rules: [], triggers: [] }));
    }, []);

    useEffect(() => {
        load();
        api.get('/projects').then(({ data: res }) => setProjects(res.projects || [])).catch(() => {});
    }, [load]);

    function open(rule) {
        setErrors({});
        setForm(rule === 'new' ? BLANK : { ...rule, project_id: rule.project?.id ?? '', tasks: rule.tasks.map((x) => ({ description: '', due_in_days: '', assignee: 'none', ...x })) });
        setEditing(rule);
    }

    const setTask = (i, patch) => setForm((f) => ({ ...f, tasks: f.tasks.map((x, j) => (j === i ? { ...x, ...patch } : x)) }));

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const body = {
            name: form.name, trigger: form.trigger, project_id: Number(form.project_id), is_active: form.is_active,
            tasks: form.tasks.filter((x) => x.title.trim()).map((x) => ({ title: x.title.trim(), description: x.description || null, assignee: x.assignee, due_in_days: x.due_in_days === '' ? null : Number(x.due_in_days) })),
        };
        try {
            if (editing === 'new') await api.post('/hrms/automation-rules', body);
            else await api.put(`/hrms/automation-rules/${editing.id}`, body);
            toast.success(t('hrms.hrAutomation.saved'));
            setEditing(null);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || err.response?.data?.message });
        } finally {
            setSaving(false);
        }
    }

    async function remove(rule) {
        if (!(await confirm({ title: t('hrms.hrAutomation.deleteTitle'), message: t('hrms.hrAutomation.deleteMessage', { name: rule.name }), variant: 'danger' }))) return;
        await api.delete(`/hrms/automation-rules/${rule.id}`).catch(() => toast.error(t('hrms.hrAutomation.failed')));
        load();
    }

    if (!data) return <div className="flex justify-center py-12"><Spinner /></div>;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.hrAutomation.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.hrAutomation.subtitle')}</p>
                </div>
                <Button onClick={() => open('new')}>{t('hrms.hrAutomation.add')}</Button>
            </div>

            <Card>
                {data.rules.length === 0 ? (
                    <p className="py-8 text-center text-sm text-gray-500">{t('hrms.hrAutomation.empty')}</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {data.rules.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <p className="font-medium text-gray-900">{r.name} {!r.is_active && <Badge tone="neutral">{t('hrms.hrAutomation.off')}</Badge>}</p>
                                    <p className="text-sm text-gray-500">
                                        {t(`hrms.hrAutomation.triggers.${r.trigger.replace('.', '_')}`, { defaultValue: r.trigger })} → {t('hrms.hrAutomation.createsIn', { count: r.tasks.length, project: r.project?.name })}
                                    </p>
                                    <p className="text-xs text-gray-400">{t('hrms.hrAutomation.ran', { count: r.run_count })}</p>
                                </div>
                                <div className="flex gap-2">
                                    <Button size="sm" variant="secondary" onClick={() => open(r)}>{t('hrms.hrAutomation.edit')}</Button>
                                    <Button size="sm" variant="ghost" onClick={() => remove(r)}>{t('hrms.hrAutomation.delete')}</Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? t('hrms.hrAutomation.addTitle') : t('hrms.hrAutomation.editTitle')} subtitle={t('hrms.hrAutomation.placeholders')}>
                <form onSubmit={save}>
                    <div className="space-y-4 px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <Input label={t('hrms.hrAutomation.name')} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} required />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label htmlFor="hra-trigger" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.hrAutomation.when')}</label>
                                <select id="hra-trigger" className={fieldClass} value={form.trigger} onChange={(e) => setForm((f) => ({ ...f, trigger: e.target.value }))}>
                                    {data.triggers.map((x) => <option key={x} value={x}>{t(`hrms.hrAutomation.triggers.${x.replace('.', '_')}`, { defaultValue: x })}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="hra-project" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.hrAutomation.project')}</label>
                                <select id="hra-project" className={fieldClass} value={form.project_id} onChange={(e) => setForm((f) => ({ ...f, project_id: e.target.value }))} required>
                                    <option value="">—</option>
                                    {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                                </select>
                                {errors.project_id && <p className="mt-1 text-xs text-red-600">{errors.project_id}</p>}
                            </div>
                        </div>
                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium text-gray-700">{t('hrms.hrAutomation.tasks')}</legend>
                            {form.tasks.map((task, i) => (
                                <div key={i} className="space-y-2 rounded-lg border border-gray-200 p-3">
                                    <Input label={t('hrms.hrAutomation.taskTitle')} value={task.title} onChange={(e) => setTask(i, { title: e.target.value })} maxLength={255} />
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label className="mb-1 block text-xs font-medium text-gray-600">{t('hrms.hrAutomation.assignee')}</label>
                                            <select className={fieldClass} value={task.assignee} onChange={(e) => setTask(i, { assignee: e.target.value })}>
                                                {['none', 'employee', 'manager'].map((a) => <option key={a} value={a}>{t(`hrms.hrAutomation.assignees.${a}`)}</option>)}
                                            </select>
                                        </div>
                                        <Input label={t('hrms.hrAutomation.dueIn')} type="number" min="0" max="365" value={task.due_in_days} onChange={(e) => setTask(i, { due_in_days: e.target.value })} />
                                    </div>
                                    {form.tasks.length > 1 && <Button type="button" size="sm" variant="ghost" onClick={() => setForm((f) => ({ ...f, tasks: f.tasks.filter((_, j) => j !== i) }))}>{t('hrms.hrAutomation.removeTask')}</Button>}
                                </div>
                            ))}
                            {errors.tasks && <p className="text-xs text-red-600">{errors.tasks}</p>}
                            <Button type="button" size="sm" variant="secondary" onClick={() => setForm((f) => ({ ...f, tasks: [...f.tasks, { title: '', description: '', assignee: 'none', due_in_days: '' }] }))}>{t('hrms.hrAutomation.addTask')}</Button>
                        </fieldset>
                        <Switch label={t('hrms.hrAutomation.active')} checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} />
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setEditing(null)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" loading={saving} disabled={!form.name.trim() || !form.project_id}>{t('tms.columns.save')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
