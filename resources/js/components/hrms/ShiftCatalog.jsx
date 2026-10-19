import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Card from '../ui/Card';
import EmptyState from '../ui/EmptyState';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import Select from '../ui/Select';
import ContentSkeleton from '../ui/ContentSkeleton';
import { Table, Th, Td } from '../ui/Table';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../ui/ConfirmDialog';

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

const emptyForm = {
    name: '',
    code: '',
    start_time: '09:00',
    end_time: '18:00',
    break_minutes: 60,
    grace_minutes: 0,
    description: '',
    working_days: ['mon', 'tue', 'wed', 'thu', 'fri'],
    is_active: true,
    split: false,
    segments: [
        { start: '09:00', end: '13:00' },
        { start: '17:00', end: '21:00' },
    ],
};

/**
 * The shift catalogue: list, create/edit (including split shifts) and delete.
 * Mutations are hidden without `canManage` (the backend 403s regardless).
 * Starter patterns cannot be deleted; deactivate them instead.
 */
export default function ShiftCatalog({ canManage, onChanged }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [shifts, setShifts] = useState(null);
    const [error, setError] = useState(null);
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});

    const load = useCallback(() => {
        return api
            .get('/hrms/shifts')
            .then(({ data }) => {
                setShifts(data.shifts ?? []);
                onChanged?.(data.shifts ?? []);
            })
            .catch(() => setError(t('hrms.shiftCatalog.loadFailed')));
    }, [onChanged, t]);

    useEffect(() => {
        load();
    }, [load]);

    function openEditor(shift) {
        setEditing(shift);
        setErrors({});
        setForm(
            shift
                ? {
                      ...emptyForm,
                      ...shift,
                      description: shift.description ?? '',
                      split: shift.is_split,
                      segments: shift.is_split ? shift.segments : emptyForm.segments,
                  }
                : emptyForm,
        );
        setOpen(true);
    }

    function toggleDay(day) {
        const days = form.working_days.includes(day) ? form.working_days.filter((d) => d !== day) : [...form.working_days, day];
        setForm({ ...form, working_days: DAYS.filter((d) => days.includes(d)) });
    }

    function setSegment(index, key, value) {
        setForm({ ...form, segments: form.segments.map((s, i) => (i === index ? { ...s, [key]: value } : s)) });
    }

    async function save(e) {
        e.preventDefault();
        setErrors({});

        const payload = {
            name: form.name,
            code: form.code,
            description: form.description || null,
            break_minutes: Number(form.break_minutes) || 0,
            grace_minutes: Number(form.grace_minutes) || 0,
            working_days: form.working_days,
            is_active: form.is_active,
            ...(form.split ? { segments: form.segments } : { segments: null, start_time: form.start_time, end_time: form.end_time }),
        };

        try {
            if (editing) {
                await api.put(`/hrms/shifts/${editing.id}`, payload);
                toast.success(t('hrms.shiftCatalog.updated'));
            } else {
                await api.post('/hrms/shifts', payload);
                toast.success(t('hrms.shiftCatalog.created'));
            }

            setOpen(false);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(shift) {
        if (!(await confirm({ title: t('hrms.shiftCatalog.deleteTitle'), message: t('hrms.shiftCatalog.deleteMessage', { name: shift.name }), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/shifts/${shift.id}`);
            toast.success(t('hrms.shiftCatalog.deleted'));
            load();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? t('hrms.shiftCatalog.deleteFailed'));
        }
    }

    return (
        <>
            {error && <Alert>{error}</Alert>}

            <Card
                dense
                title={t('hrms.shiftCatalog.title')}
                actions={canManage ? <Button onClick={() => openEditor(null)}>{t('hrms.shiftCatalog.new')}</Button> : null}
            >
                {!shifts ? (
                    <ContentSkeleton size="sm" />
                ) : shifts.length === 0 ? (
                    <EmptyState title={t('hrms.shiftCatalog.emptyTitle')} description={t('hrms.shiftCatalog.emptyHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.shiftCatalog.thShift')}</Th>
                                <Th>{t('hrms.shiftCatalog.thHours')}</Th>
                                <Th>{t('hrms.shiftCatalog.thBreak')}</Th>
                                <Th>{t('hrms.shiftCatalog.thDays')}</Th>
                                <Th>{t('hrms.shiftCatalog.thStatus')}</Th>
                                <Th><span className="sr-only">{t('hrms.shiftCatalog.thActions')}</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {shifts.map((shift) => (
                                <tr key={shift.id}>
                                    <Td>
                                        <span className="font-medium">{shift.name}</span>
                                        <span className="ml-2 font-mono text-xs text-gray-400">{shift.code}</span>
                                        {shift.is_night && <span className="ml-2 rounded bg-[var(--accent-soft)] px-1.5 text-xs text-[var(--accent-soft-text)]">{t('hrms.shiftCatalog.night')}</span>}
                                        {shift.is_split && <span className="ml-2 rounded bg-amber-50 px-1.5 text-xs text-amber-600">{t('hrms.shiftCatalog.split')}</span>}
                                    </Td>
                                    <Td>
                                        {shift.is_split
                                            ? shift.segments.map((s) => `${s.start}–${s.end}`).join(' · ')
                                            : `${shift.start_time}–${shift.end_time}`}
                                    </Td>
                                    <Td>{shift.break_minutes}m</Td>
                                    <Td>{(shift.working_days ?? []).map((d) => t(`hrms.shiftCatalog.day${d.charAt(0).toUpperCase()}${d.slice(1)}`)).join(' ') || '—'}</Td>
                                    <Td>{shift.is_active ? t('hrms.shiftCatalog.active') : t('hrms.shiftCatalog.inactive')}</Td>
                                    <Td>
                                        {canManage && (
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openEditor(shift)}>{t('hrms.shiftCatalog.edit')}</Button>
                                                {!shift.is_system && <Button variant="secondary" onClick={() => remove(shift)}>{t('hrms.shiftCatalog.delete')}</Button>}
                                            </div>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Modal open={open} onClose={() => setOpen(false)} title={editing ? t('hrms.shiftCatalog.editTitle') : t('hrms.shiftCatalog.newTitle')}>
                <form onSubmit={save} className="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-2">
                    <Input label={t('hrms.shiftCatalog.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} />
                    <Input label={t('hrms.shiftCatalog.code')} value={form.code} disabled={editing?.is_system} onChange={(e) => setForm({ ...form, code: e.target.value })} error={errors.code} />
                    <Select label={t('hrms.shiftCatalog.splitShift')} value={String(form.split)} onChange={(e) => setForm({ ...form, split: e.target.value === 'true' })}>
                        <option value="false">{t('hrms.shiftCatalog.splitNo')}</option>
                        <option value="true">{t('hrms.shiftCatalog.splitYes')}</option>
                    </Select>
                    <Select label={t('hrms.shiftCatalog.activeLabel')} value={String(form.is_active)} onChange={(e) => setForm({ ...form, is_active: e.target.value === 'true' })}>
                        <option value="true">{t('hrms.shiftCatalog.yes')}</option>
                        <option value="false">{t('hrms.shiftCatalog.no')}</option>
                    </Select>
                    {form.split ? (
                        <div className="space-y-2 sm:col-span-2">
                            {form.segments.map((segment, index) => (
                                <div key={index} className="grid grid-cols-2 gap-2">
                                    <Input type="time" label={t('hrms.shiftCatalog.blockStart', { n: index + 1 })} value={segment.start} onChange={(e) => setSegment(index, 'start', e.target.value)} />
                                    <Input type="time" label={t('hrms.shiftCatalog.blockEnd', { n: index + 1 })} value={segment.end} onChange={(e) => setSegment(index, 'end', e.target.value)} />
                                </div>
                            ))}
                            {errors.segments && <Alert>{errors.segments}</Alert>}
                            <p className="text-xs text-gray-500">{t('hrms.shiftCatalog.gapNote')}</p>
                        </div>
                    ) : (
                        <>
                            <Input type="time" label={t('hrms.shiftCatalog.start')} value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} error={errors.start_time} />
                            <Input type="time" label={t('hrms.shiftCatalog.endOvernight')} value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} error={errors.end_time} />
                            <Input type="number" min="0" label={t('hrms.shiftCatalog.break')} value={form.break_minutes} onChange={(e) => setForm({ ...form, break_minutes: e.target.value })} error={errors.break_minutes} />
                        </>
                    )}
                    <Input type="number" min="0" label={t('hrms.shiftCatalog.grace')} value={form.grace_minutes} onChange={(e) => setForm({ ...form, grace_minutes: e.target.value })} error={errors.grace_minutes} />
                    <div className="sm:col-span-2">
                        <span className="mb-1 block text-sm font-medium text-gray-700">{t('hrms.shiftCatalog.workingDays')}</span>
                        <div className="flex flex-wrap gap-1.5">
                            {DAYS.map((day) => (
                                <button
                                    key={day}
                                    type="button"
                                    onClick={() => toggleDay(day)}
                                    className={`rounded-full px-2.5 py-1 text-xs ${form.working_days.includes(day) ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'bg-gray-100 text-gray-600'}`}
                                >
                                    {t(`hrms.shiftCatalog.day${day.charAt(0).toUpperCase()}${day.slice(1)}`)}
                                </button>
                            ))}
                        </div>
                    </div>
                    <Input label={t('hrms.shiftCatalog.description')} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} className="sm:col-span-2" />
                    {errors.form && <Alert>{errors.form}</Alert>}
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>{t('hrms.shiftCatalog.cancel')}</Button>
                        <Button type="submit">{t('hrms.shiftCatalog.save')}</Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
