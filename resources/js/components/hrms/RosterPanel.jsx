import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Input from '../ui/Input';
import Select from '../ui/Select';
import Spinner from '../ui/Spinner';
import { Table, Th, Td, TableEmpty } from '../ui/Table';
import { useToast } from '../../context/ToastContext';

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

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
    const toast = useToast();
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
            .catch(() => setError('Unable to load the roster.'));
    }, [from, to, canView]);

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
            toast.success('Roster saved.');
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(id) {
        if (!window.confirm('Remove this roster row?')) return;

        try {
            await api.delete(`/hrms/shifts/rosters/${id}`);
            toast.success('Roster row removed.');
            load();
        } catch {
            toast.error('Unable to remove the roster row.');
        }
    }

    return (
        <div className="space-y-4">
            {error && <Alert>{error}</Alert>}

            <Card dense title="Roster">
                <div className="mb-3 flex flex-wrap items-end gap-2">
                    <Input type="date" label="From" value={from} onChange={(e) => setFrom(e.target.value)} />
                    <Input type="date" label="To" value={to} onChange={(e) => setTo(e.target.value)} />
                </div>

                {!rosters ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Person</Th>
                                <Th>Shift</Th>
                                <Th>From</Th>
                                <Th>To</Th>
                                <Th>Offs</Th>
                                {canManage && <Th><span className="sr-only">Actions</span></Th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rosters.map((row) => (
                                <tr key={row.id}>
                                    <Td>{row.employee_name ?? `#${row.employee_id}`}</Td>
                                    <Td>{row.shift_name ?? (row.is_flexible ? 'Flexible' : 'Day off')}</Td>
                                    <Td>{row.effective_from}</Td>
                                    <Td>{row.effective_to ?? 'open'}</Td>
                                    <Td>{DAY_LABELS.filter((_, i) => row.weekly_offs?.[i] === 1).join(' ') || '—'}</Td>
                                    {canManage && (
                                        <Td>
                                            <div className="flex justify-end">
                                                <Button variant="secondary" onClick={() => remove(row.id)}>Remove</Button>
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                            {rosters.length === 0 && <TableEmpty colSpan={canManage ? 6 : 5}>No roster rows in this window.</TableEmpty>}
                        </tbody>
                    </Table>
                )}
            </Card>

            {canManage && (
                <Card dense title="Assign a shift">
                    <form onSubmit={assign} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Select label="Person" value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })} error={errors.employee_id}>
                            <option value="">Choose…</option>
                            {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.name}</option>)}
                        </Select>
                        <Select label="Shift" value={form.shift_id} onChange={(e) => setForm({ ...form, shift_id: e.target.value })} error={errors.shift_id}>
                            <option value="">Flexible / none</option>
                            {shifts.filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                        <Input type="date" label="From" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} error={errors.effective_from} />
                        <Input type="date" label="To (blank = open-ended)" value={form.effective_to} onChange={(e) => setForm({ ...form, effective_to: e.target.value })} error={errors.effective_to} />
                        <div className="sm:col-span-2">
                            <span className="mb-1 block text-sm font-medium text-gray-700">Weekly offs</span>
                            <div className="flex flex-wrap gap-1.5">
                                {DAY_LABELS.map((label, index) => (
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
                            <Button type="submit">Save roster</Button>
                        </div>
                    </form>
                </Card>
            )}
        </div>
    );
}
