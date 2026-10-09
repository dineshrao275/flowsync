import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Input from '../ui/Input';
import Select from '../ui/Select';
import { Table, Th, Td, TableEmpty } from '../ui/Table';
import { useToast } from '../../context/ToastContext';

const empty = { name: '', from_date: '', to_date: '', leave_type_id: '', department_id: '', reason: '' };

/**
 * Leave blackout windows: date ranges (company-wide, or narrowed to a leave
 * type) in which leave cannot be asked for. Managers add and remove them.
 */
export default function LeaveBlackoutPanel({ types }) {
    const toast = useToast();
    const [rows, setRows] = useState(null);
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState({});

    const load = useCallback(() => {
        api.get('/hrms/leave/blackouts').then(({ data }) => setRows(data.blackouts ?? [])).catch(() => setRows([]));
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function save(e) {
        e.preventDefault();
        setErrors({});

        try {
            await api.post('/hrms/leave/blackouts', {
                name: form.name,
                from_date: form.from_date,
                to_date: form.to_date,
                leave_type_id: form.leave_type_id ? Number(form.leave_type_id) : null,
                reason: form.reason || null,
            });
            toast.success('Blackout created.');
            setForm(empty);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(id) {
        if (!window.confirm('Delete this blackout window?')) return;

        try {
            await api.delete(`/hrms/leave/blackouts/${id}`);
            load();
        } catch {
            toast.error('Unable to delete the blackout.');
        }
    }

    return (
        <div className="space-y-4">
            <Card dense title="Blackout windows">
                <Table>
                    <thead>
                        <tr><Th>Name</Th><Th>From</Th><Th>To</Th><Th>Applies to</Th><Th><span className="sr-only">Actions</span></Th></tr>
                    </thead>
                    <tbody>
                        {(rows ?? []).map((row) => (
                            <tr key={row.id}>
                                <Td>{row.name}</Td>
                                <Td>{row.from_date}</Td>
                                <Td>{row.to_date}</Td>
                                <Td>{[row.leave_type ?? 'all types', row.department ?? 'everyone'].join(' · ')}</Td>
                                <Td><div className="flex justify-end"><Button variant="secondary" onClick={() => remove(row.id)}>Delete</Button></div></Td>
                            </tr>
                        ))}
                        {rows && rows.length === 0 && <TableEmpty colSpan={5}>No blackout windows.</TableEmpty>}
                    </tbody>
                </Table>
            </Card>

            <Card dense title="New blackout">
                <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} />
                    <Select label="Leave type" value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })} error={errors.leave_type_id}>
                        <option value="">All types</option>
                        {(types ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                    <Input type="date" label="From" value={form.from_date} onChange={(e) => setForm({ ...form, from_date: e.target.value })} error={errors.from_date} />
                    <Input type="date" label="To" value={form.to_date} onChange={(e) => setForm({ ...form, to_date: e.target.value })} error={errors.to_date} />
                    <Input label="Reason (shown to employees)" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={errors.reason} className="sm:col-span-2" />
                    <div className="sm:col-span-2"><Button type="submit">Add blackout</Button></div>
                </form>
            </Card>
        </div>
    );
}
