import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatDateOnly } from '../../utils/format';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const TONE = { pending: 'warning', approved: 'info', applied: 'success', rejected: 'danger', withdrawn: 'neutral' };
const BLANK = { employee_id: '', type: 'transfer', effective_date: '', reason: '', department_id: '', designation_id: '', location_id: '', manager_id: '', employment_type_id: '' };

function Select({ id, label, value, onChange, options, error }) {
    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-gray-700">{label}</label>
            <select id={id} className={fieldClass} value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">—</option>
                {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Transfers, promotions and confirmations: request, decide, and see what is scheduled. */
export default function EmployeeChanges() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.changes.pageTitle'));
    const { can } = useAuth();
    const toast = useToast();
    const [data, setData] = useState(null);
    const [options, setOptions] = useState({});
    const [people, setPeople] = useState([]);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(BLANK);
    const [errors, setErrors] = useState({});
    const [note, setNote] = useState({});
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/employee-changes').then(({ data: res }) => setData(res)).catch(() => setError(t('hrms.changes.loadFailed')));
    }, [t]);

    useEffect(() => {
        load();
        api.get('/hrms/employees', { params: { per_page: 100 } }).then(({ data: res }) => { setOptions(res.filters || {}); setPeople(res.employees || []); }).catch(() => {});
    }, [load]);

    const set = (key) => (value) => setForm((f) => ({ ...f, [key]: value }));
    const canRequest = can('hrms.employees.manage') || people.length > 0;

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const body = {
            type: form.type, effective_date: form.effective_date, reason: form.reason || null,
            changes: Object.fromEntries(['department_id', 'designation_id', 'location_id', 'manager_id', 'employment_type_id'].filter((k) => form[k] !== '').map((k) => [k, Number(form[k])])),
        };
        try {
            await api.post(`/hrms/employees/${form.employee_id}/changes`, body);
            toast.success(t('hrms.changes.requested'));
            setOpen(false);
            setForm(BLANK);
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors({ ...fe, form: fe.form || err.response?.data?.message });
        } finally {
            setSaving(false);
        }
    }

    async function act(c, action) {
        setError(null);
        try {
            await api.post(`/hrms/employee-changes/${c.id}/${action}`, action === 'withdraw' ? {} : { note: note[c.id] || '' });
            toast.success(t(`hrms.changes.done.${action}`));
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setError(fe.decision_note || fe.note || fe.form || fe.approval || t('hrms.changes.actionFailed'));
        }
    }

    if (!data) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    const summary = (c) => ['department_id', 'designation_id', 'location_id', 'manager_id', 'employment_type_id'].filter((k) => k in c.changes).map((k) => t(`hrms.changes.fields.${k}`)).join(', ') || (c.type === 'confirmation' ? t('hrms.changes.confirmation') : '');

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.changes.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.changes.subtitle')}</p>
                </div>
                {canRequest && <Button onClick={() => setOpen(true)}>{t('hrms.changes.request')}</Button>}
            </div>
            {error && <Alert>{error}</Alert>}

            <Card>
                {data.changes.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.changes.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {data.changes.map((c) => (
                            <li key={c.id} className="py-3">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-gray-900">{c.employee?.name} · {t(`hrms.changes.types.${c.type}`)} <Badge tone={TONE[c.status]}>{t(`hrms.changes.status.${c.status}`)}</Badge></p>
                                        <p className="text-sm text-gray-500">{t('hrms.changes.effective', { date: formatDateOnly(c.effective_date) })}{summary(c) ? ` · ${summary(c)}` : ''}</p>
                                        {c.reason && <p className="text-sm text-gray-600">{c.reason}</p>}
                                        {c.decision_note && <p className="text-xs text-gray-500">{t('hrms.changes.note', { note: c.decision_note })}</p>}
                                    </div>
                                    {c.status === 'pending' && !c.can_decide && <Button size="sm" variant="ghost" onClick={() => act(c, 'withdraw')}>{t('hrms.changes.withdraw')}</Button>}
                                </div>
                                {c.can_decide && (
                                    <div className="mt-3 flex flex-wrap items-end gap-2">
                                        <div className="w-64"><Input label={t('hrms.changes.noteLabel')} compact value={note[c.id] || ''} onChange={(e) => setNote((n) => ({ ...n, [c.id]: e.target.value }))} /></div>
                                        <Button variant="secondary" onClick={() => act(c, 'reject')}>{t('hrms.changes.reject')}</Button>
                                        <Button onClick={() => act(c, 'approve')}>{t('hrms.changes.approve')}</Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={open} onClose={() => setOpen(false)} title={t('hrms.changes.request')} subtitle={t('hrms.changes.modalHint')}>
                <form onSubmit={submit}>
                    <div className="space-y-4 px-6 py-5">
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <Select id="chg-emp" label={t('hrms.changes.employee')} value={form.employee_id} onChange={set('employee_id')} options={people.map((p) => ({ id: p.id, name: p.name }))} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label htmlFor="chg-type" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.changes.type')}</label>
                                <select id="chg-type" className={fieldClass} value={form.type} onChange={(e) => set('type')(e.target.value)}>
                                    {data.types.map((ty) => <option key={ty} value={ty}>{t(`hrms.changes.types.${ty}`)}</option>)}
                                </select>
                            </div>
                            <Input label={t('hrms.changes.effectiveDate')} type="date" value={form.effective_date} onChange={(e) => set('effective_date')(e.target.value)} error={errors.effective_date} required />
                        </div>
                        {form.type !== 'confirmation' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Select id="chg-dep" label={t('hrms.changes.fields.department_id')} value={form.department_id} onChange={set('department_id')} options={options.departments || []} />
                                <Select id="chg-des" label={t('hrms.changes.fields.designation_id')} value={form.designation_id} onChange={set('designation_id')} options={options.designations || []} />
                                <Select id="chg-loc" label={t('hrms.changes.fields.location_id')} value={form.location_id} onChange={set('location_id')} options={options.locations || []} />
                                <Select id="chg-mgr" label={t('hrms.changes.fields.manager_id')} value={form.manager_id} onChange={set('manager_id')} options={options.managers || []} />
                                <Select id="chg-et" label={t('hrms.changes.fields.employment_type_id')} value={form.employment_type_id} onChange={set('employment_type_id')} options={options.employment_types || []} />
                            </div>
                        )}
                        {errors.changes && <p className="text-xs text-red-600">{errors.changes}</p>}
                        <Input label={t('hrms.changes.reason')} value={form.reason} onChange={(e) => set('reason')(e.target.value)} maxLength={1000} />
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" loading={saving} disabled={!form.employee_id || !form.effective_date}>{t('hrms.changes.send')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
