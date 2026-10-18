import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Input from '../ui/Input';
import Select from '../ui/Select';
import ContentSkeleton from '../ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../ui/Table';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../ui/ConfirmDialog';
import { weekdayNames } from '../../utils/calendar';

function isoDate(date) {
    return date.toISOString().slice(0, 10);
}

/**
 * Roster window + assignment: who is on which shift between two dates, and a
 * form to put someone on a shift (overlaps are replaced server-side). With no
 * `canManage` the panel is read-only; a person without the view permission
 * sees only their own rows via the `mine` endpoint.
 */
export default function RosterPanel({ shifts, canManage, canView }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const today = new Date();
    const [from, setFrom] = useState(isoDate(today));
    const [to, setTo] = useState(isoDate(new Date(today.getTime() + 13 * 86400000)));
    const [rosters, setRosters] = useState(null);
    const [employees, setEmployees] = useState([]);
    const [error, setError] = useState(null);
    const [form, setForm] = useState({ employee_id: '', shift_id: '', effective_from: '', effective_to: '', weekly_offs: [0, 0, 0, 0, 0, 1, 1] });
    const [errors, setErrors] = useState({});

    const load = useCallback(() => {
        setError(null);

        return api
            .get(canView ? '/hrms/shifts/rosters' : '/hrms/shifts/rosters/mine', { params: { from, to } })
            .then(({ data }) => setRosters(data.rosters ?? []))
            .catch(() => setError(t('hrms.roster.loadFailed')));
    }, [from, to, canView, t]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!canManage) return;

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
    }, [canManage]);

    function toggleOff(index) {
        setForm({ ...form, weekly_offs: form.weekly_offs.map((v, i) => (i === index ? 1 - v : v)) });
    }

    async function assign(e) {
        e.preventDefault();
        setErrors({});

        try {
            await api.post('/hrms/shifts/rosters', {
                employee_id: Number(form.employee_id),
                shift_id: form.shift_id ? Number(form.shift_id) : null,
                effective_from: form.effective_from,
                effective_to: form.effective_to || null,
                weekly_offs: form.weekly_offs,
            });
            toast.success(t('hrms.roster.saved'));
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(id) {
        if (!(await confirm({ title: t('hrms.roster.removeTitle'), message: t('hrms.roster.removeMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/shifts/rosters/${id}`);
            toast.success(t('hrms.roster.removed'));
            load();
        } catch {
            toast.error(t('hrms.roster.removeFailed'));
        }
    }

    return (
        <div className="space-y-4">
            {error && <Alert>{error}</Alert>}

            <Card dense title={t('hrms.roster.title')}>
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Input type="date" label={t('hrms.roster.from')} value={from} onChange={(e) => setFrom(e.target.value)} />
                    <Input type="date" label={t('hrms.roster.to')} value={to} onChange={(e) => setTo(e.target.value)} />
                </div>

                {!rosters ? (
                    <ContentSkeleton size="sm" />
                ) : (
                    <Table compact>
                        <thead>
                            <tr>
                                <Th>{t('hrms.roster.thPerson')}</Th>
                                <Th>{t('hrms.roster.thShift')}</Th>
                                <Th>{t('hrms.roster.thFrom')}</Th>
                                <Th>{t('hrms.roster.thTo')}</Th>
                                <Th>{t('hrms.roster.thOffs')}</Th>
                                {canManage && <Th><span className="sr-only">{t('hrms.roster.thActions')}</span></Th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rosters.map((row) => (
                                <tr key={row.id}>
                                    <Td>{row.employee_name ?? `#${row.employee_id}`}</Td>
                                    <Td>{row.shift_name ?? (row.is_flexible ? t('hrms.roster.flexible') : t('hrms.roster.dayOff'))}</Td>
                                    <Td>{row.effective_from}</Td>
                                    <Td>{row.effective_to ?? t('hrms.roster.openEnded')}</Td>
                                    <Td>{weekdayNames().filter((_, i) => row.weekly_offs?.[i] === 1).join(' ') || '—'}</Td>
                                    {canManage && (
                                        <Td>
                                            <div className="flex justify-end">
                                                <Button variant="secondary" onClick={() => remove(row.id)}>{t('hrms.roster.remove')}</Button>
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                            {rosters.length === 0 && <TableEmpty colSpan={canManage ? 6 : 5}>{t('hrms.roster.empty')}</TableEmpty>}
                        </tbody>
                    </Table>
                )}
            </Card>

            {canManage && (
                <Card dense title={t('hrms.roster.assignTitle')}>
                    <form onSubmit={assign} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Select label={t('hrms.roster.person')} value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })} error={errors.employee_id}>
                            <option value="">{t('hrms.roster.choose')}</option>
                            {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.name}</option>)}
                        </Select>
                        <Select label={t('hrms.roster.shift')} value={form.shift_id} onChange={(e) => setForm({ ...form, shift_id: e.target.value })} error={errors.shift_id}>
                            <option value="">{t('hrms.roster.flexibleNone')}</option>
                            {shifts.filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                        <Input type="date" label={t('hrms.roster.from')} value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} error={errors.effective_from} />
                        <Input type="date" label={t('hrms.roster.toOpen')} value={form.effective_to} onChange={(e) => setForm({ ...form, effective_to: e.target.value })} error={errors.effective_to} />
                        <div className="sm:col-span-2">
                            <span className="mb-1 block text-sm font-medium text-gray-700">{t('hrms.roster.weeklyOffs')}</span>
                            <div className="flex flex-wrap gap-1.5">
                                {weekdayNames().map((label, index) => (
                                    <button
                                        key={label}
                                        type="button"
                                        onClick={() => toggleOff(index)}
                                        className={`rounded-full px-2.5 py-1 text-xs ${form.weekly_offs[index] === 1 ? 'bg-rose-100 text-rose-700' : 'bg-gray-100 text-gray-600'}`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        {errors.form && <Alert>{errors.form}</Alert>}
                        <div className="sm:col-span-2">
                            <Button type="submit">{t('hrms.roster.save')}</Button>
                        </div>
                    </form>
                </Card>
            )}
        </div>
    );
}
