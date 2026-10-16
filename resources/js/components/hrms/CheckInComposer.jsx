import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import { useToast } from '../../context/ToastContext';

const MOODS = [
    { value: '', key: 'noMood' },
    { value: 'great', key: 'great' },
    { value: 'good', key: 'good' },
    { value: 'ok', key: 'ok' },
    { value: 'low', key: 'low' },
];

/**
 * File a check-in note for a cycle: body, mood, blockers, and the support
 * flag. Notes append — there is no edit path, because a dated note is
 * history the moment it is written.
 */
export default function CheckInComposer({ cycleId, employeeId, onFiled }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [form, setForm] = useState({ body: '', mood: '', blockers: '', needs_support: false });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    async function save(e) {
        e.preventDefault();
        setErrors({});
        setSaving(true);

        try {
            await api.post(`/hrms/performance/cycles/${cycleId}/check-ins`, {
                employee_id: Number(employeeId),
                body: form.body,
                mood: form.mood || null,
                blockers: form.blockers || null,
                needs_support: form.needs_support,
            });
            toast.success(t('hrms.checkIn.filed'));
            setForm({ body: '', mood: '', blockers: '', needs_support: false });
            onFiled?.();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    if (!employeeId) {
        return <p className="text-sm text-gray-500">{t('hrms.checkIn.selectPerson')}</p>;
    }

    return (
        <form onSubmit={save} className="grid gap-3">
            {errors.form && <Alert>{errors.form}</Alert>}
            <Input
                label={t('hrms.checkIn.howGoing')}
                value={form.body}
                error={errors.body}
                onChange={(e) => setForm({ ...form, body: e.target.value })}
                required
            />
            <div className="grid grid-cols-2 gap-3">
                <Select label={t('hrms.checkIn.mood')} value={form.mood} error={errors.mood} onChange={(e) => setForm({ ...form, mood: e.target.value })}>
                    {MOODS.map((m) => <option key={m.value} value={m.value}>{t(`hrms.checkIn.${m.key}`)}</option>)}
                </Select>
                <Input label={t('hrms.checkIn.blockers')} value={form.blockers} error={errors.blockers} onChange={(e) => setForm({ ...form, blockers: e.target.value })} />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.needs_support} onChange={(e) => setForm({ ...form, needs_support: e.target.checked })} />
                {t('hrms.checkIn.needsSupport')}
            </label>
            <div><Button type="submit" loading={saving}>{t('hrms.checkIn.file')}</Button></div>
        </form>
    );
}
