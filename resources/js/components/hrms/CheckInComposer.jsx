import { useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import { useToast } from '../../context/ToastContext';

const MOODS = [
    { value: '', label: 'No mood' },
    { value: 'great', label: 'Great' },
    { value: 'good', label: 'Good' },
    { value: 'ok', label: 'Ok' },
    { value: 'low', label: 'Low' },
];

/**
 * File a check-in note for a cycle: body, mood, blockers, and the support
 * flag. Notes append — there is no edit path, because a dated note is
 * history the moment it is written.
 */
export default function CheckInComposer({ cycleId, employeeId, onFiled }) {
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
            toast.success('Check-in filed.');
            setForm({ body: '', mood: '', blockers: '', needs_support: false });
            onFiled?.();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    if (!employeeId) {
        return <p className="text-sm text-gray-500">Select a person to file a check-in for.</p>;
    }

    return (
        <form onSubmit={save} className="grid gap-3">
            {errors.form && <Alert>{errors.form}</Alert>}
            <Input
                label="How is it going?"
                value={form.body}
                error={errors.body}
                onChange={(e) => setForm({ ...form, body: e.target.value })}
                required
            />
            <div className="grid grid-cols-2 gap-3">
                <Select label="Mood" value={form.mood} error={errors.mood} onChange={(e) => setForm({ ...form, mood: e.target.value })}>
                    {MOODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                </Select>
                <Input label="Blockers" value={form.blockers} error={errors.blockers} onChange={(e) => setForm({ ...form, blockers: e.target.value })} />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.needs_support} onChange={(e) => setForm({ ...form, needs_support: e.target.checked })} />
                Needs support
            </label>
            <div><Button type="submit" loading={saving}>File check-in</Button></div>
        </form>
    );
}
