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

/**
 * Rotation templates ("6 on, 2 off") and applying one to people over a
 * window. A slot is a shift or a day off; applying writes ordinary roster
 * rows server-side.
 */
export default function RotationPanel({ shifts, canManage }) {
    const toast = useToast();
    const [rotations, setRotations] = useState(null);
    const [employees, setEmployees] = useState([]);
    const [form, setForm] = useState({ name: '', code: '', cycle: ['', ''] });
    const [errors, setErrors] = useState({});
    const [apply, setApply] = useState({ rotation_id: '', employee_ids: [], from: '', to: '', offset: 0 });
    const [applyErrors, setApplyErrors] = useState({});

    const load = useCallback(() => {
        return api.get('/hrms/shifts/rotations').then(({ data }) => setRotations(data.rotations ?? [])).catch(() => setRotations([]));
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!canManage) return;

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
    }, [canManage]);

    const shiftName = (id) => (id === null ? 'off' : shifts.find((s) => s.id === id)?.code ?? `#${id}`);

    async function create(e) {
        e.preventDefault();
        setErrors({});

        try {
            await api.post('/hrms/shifts/rotations', {
                name: form.name,
                code: form.code,
                cycle: form.cycle.map((slot) => (slot === '' ? null : Number(slot))),
            });
            toast.success('Rotation created.');
            setForm({ name: '', code: '', cycle: ['', ''] });
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(id) {
        if (!window.confirm('Delete this rotation? Rows it already produced stay.')) return;

        try {
            await api.delete(`/hrms/shifts/rotations/${id}`);
            load();
        } catch {
            toast.error('Unable to delete the rotation.');
        }
    }

    async function submitApply(e) {
        e.preventDefault();
        setApplyErrors({});

        try {
            const { data } = await api.post(`/hrms/shifts/rotations/${apply.rotation_id}/apply`, {
                employee_ids: apply.employee_ids.map(Number),
                from: apply.from,
                to: apply.to,
                offset: Number(apply.offset) || 0,
            });
            toast.success(data.message);
        } catch (err) {
            setApplyErrors(fieldErrors(err));
        }
    }

    return (
        <div className="space-y-4">
            <Card dense title="Rotations">
                {!rotations ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Cycle</Th>
                                {canManage && <Th><span className="sr-only">Actions</span></Th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rotations.map((rotation) => (
                                <tr key={rotation.id}>
                                    <Td><span className="font-medium">{rotation.name}</span> <span className="font-mono text-xs text-gray-400">{rotation.code}</span></Td>
                                    <Td>{rotation.cycle.map(shiftName).join(' · ')}</Td>
                                    {canManage && (
                                        <Td><div className="flex justify-end"><Button variant="secondary" onClick={() => remove(rotation.id)}>Delete</Button></div></Td>
                                    )}
                                </tr>
                            ))}
                            {rotations.length === 0 && <TableEmpty colSpan={canManage ? 3 : 2}>No rotations yet.</TableEmpty>}
                        </tbody>
                    </Table>
                )}
            </Card>

            {canManage && (
                <>
                    <Card dense title="New rotation">
                        <form onSubmit={create} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} />
                            <Input label="Code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} error={errors.code} />
                            <div className="flex flex-wrap items-end gap-2 sm:col-span-2">
                                {form.cycle.map((slot, index) => (
                                    <Select
                                        key={index}
                                        label={`Day ${index + 1}`}
                                        value={slot}
                                        onChange={(e) => setForm({ ...form, cycle: form.cycle.map((s, i) => (i === index ? e.target.value : s)) })}
                                    >
                                        <option value="">Off</option>
                                        {shifts.filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
                                    </Select>
                                ))}
                                <Button type="button" variant="secondary" onClick={() => setForm({ ...form, cycle: [...form.cycle, ''] })}>+ day</Button>
                                {form.cycle.length > 2 && (
                                    <Button type="button" variant="secondary" onClick={() => setForm({ ...form, cycle: form.cycle.slice(0, -1) })}>− day</Button>
                                )}
                            </div>
                            {errors.cycle && <Alert>{errors.cycle}</Alert>}
                            <div className="sm:col-span-2"><Button type="submit">Create rotation</Button></div>
                        </form>
                    </Card>

                    <Card dense title="Apply a rotation">
                        <form onSubmit={submitApply} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Select label="Rotation" value={apply.rotation_id} onChange={(e) => setApply({ ...apply, rotation_id: e.target.value })}>
                                <option value="">Choose…</option>
                                {(rotations ?? []).filter((r) => r.is_active).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </Select>
                            <Input type="number" min="0" label="Start offset (cycle day)" value={apply.offset} onChange={(e) => setApply({ ...apply, offset: e.target.value })} />
                            <Input type="date" label="From" value={apply.from} onChange={(e) => setApply({ ...apply, from: e.target.value })} error={applyErrors.from} />
                            <Input type="date" label="To" value={apply.to} onChange={(e) => setApply({ ...apply, to: e.target.value })} error={applyErrors.to} />
                            <label className="sm:col-span-2 text-sm">
                                <span className="mb-1 block font-medium text-gray-700">People</span>
                                <select
                                    multiple
                                    className="h-32 w-full rounded-lg border border-gray-300 p-2"
                                    value={apply.employee_ids}
                                    onChange={(e) => setApply({ ...apply, employee_ids: Array.from(e.target.selectedOptions, (o) => o.value) })}
                                >
                                    {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.name}</option>)}
                                </select>
                            </label>
                            {(applyErrors.employee_ids || applyErrors.form) && <Alert>{applyErrors.employee_ids ?? applyErrors.form}</Alert>}
                            <div className="sm:col-span-2"><Button type="submit">Apply</Button></div>
                        </form>
                    </Card>
                </>
            )}
        </div>
    );
}
