import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Input from '../ui/Input';
import Select from '../ui/Select';
import { Table, Th, Td, TableEmpty } from '../ui/Table';
import { useToast } from '../../context/ToastContext';

/**
 * Leave admin tools: a signed, reasoned balance correction, the ledger behind
 * a person's balance, and the year-end carry-forward/lapse run (with a dry
 * run). Every call needs `hrms.leave.manage` (the backend 403s regardless).
 */
export default function LeaveAdjustPanel({ employees, types }) {
    const toast = useToast();
    const thisYear = new Date().getFullYear();
    const [form, setForm] = useState({ employee_id: '', leave_type_id: '', year: String(thisYear), quantity: '', reason: '' });
    const [errors, setErrors] = useState({});
    const [ledger, setLedger] = useState(null);
    const [roll, setRoll] = useState({ year: String(thisYear - 1), result: null });
    const [rollError, setRollError] = useState(null);

    const loadLedger = useCallback(() => {
        if (!form.employee_id) {
            setLedger(null);

            return;
        }

        api.get('/hrms/leave/ledger', { params: { employee_id: Number(form.employee_id), year: Number(form.year) || undefined } })
            .then(({ data }) => setLedger(data.ledger ?? []))
            .catch(() => setLedger([]));
    }, [form.employee_id, form.year]);

    useEffect(() => {
        loadLedger();
    }, [loadLedger]);

    async function adjust(e) {
        e.preventDefault();
        setErrors({});

        try {
            await api.post('/hrms/leave/adjustments', {
                employee_id: Number(form.employee_id),
                leave_type_id: Number(form.leave_type_id),
                year: Number(form.year),
                quantity: Number(form.quantity),
                reason: form.reason,
            });
            toast.success('Balance adjusted.');
            setForm({ ...form, quantity: '', reason: '' });
            loadLedger();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function runRollover(dryRun) {
        setRollError(null);

        try {
            const { data } = await api.post('/hrms/leave/rollover', { year: Number(roll.year), dry_run: dryRun });
            setRoll({ ...roll, result: data });
            if (!dryRun) toast.success(data.message);
        } catch (err) {
            setRollError(fieldErrors(err).year ?? 'The rollover failed.');
        }
    }

    return (
        <div className="space-y-4">
            <Card dense title="Adjust a balance">
                <form onSubmit={adjust} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Select label="Employee" value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })} error={errors.employee_id}>
                        <option value="">Choose…</option>
                        {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.name}</option>)}
                    </Select>
                    <Select label="Leave type" value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })} error={errors.leave_type_id}>
                        <option value="">Choose…</option>
                        {(types ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                    <Input label="Leave year" value={form.year} onChange={(e) => setForm({ ...form, year: e.target.value })} error={errors.year} />
                    <Input type="number" step="0.5" label="Days (+ credit / − debit)" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} error={errors.quantity} />
                    <Input label="Reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={errors.reason} className="sm:col-span-2" />
                    <div className="sm:col-span-2"><Button type="submit">Apply adjustment</Button></div>
                </form>
            </Card>

            <Card dense title="Ledger">
                {!form.employee_id ? (
                    <p className="text-sm text-gray-500">Pick an employee above to see their ledger for the year.</p>
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>When</Th><Th>Type</Th><Th>Kind</Th><Th align="right">Days</Th><Th>Note</Th><Th>By</Th></tr>
                        </thead>
                        <tbody>
                            {(ledger ?? []).map((row) => (
                                <tr key={row.id}>
                                    <Td>{row.created_at?.slice(0, 10)}</Td>
                                    <Td>{row.leave_type}</Td>
                                    <Td>{row.kind_label}</Td>
                                    <Td align="right">{row.quantity > 0 ? `+${row.quantity}` : row.quantity}</Td>
                                    <Td>{row.note}</Td>
                                    <Td>{row.actor ?? 'system'}</Td>
                                </tr>
                            ))}
                            {ledger && ledger.length === 0 && <TableEmpty colSpan={6}>No ledger rows.</TableEmpty>}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card dense title="Year-end carry-forward and lapse">
                <p className="mb-3 text-sm text-gray-500">
                    Closes a finished leave year: balances up to each type&apos;s cap carry into the next year, the rest lapses. Safe to re-run.
                </p>
                <div className="flex flex-wrap items-end gap-2">
                    <Input label="Closed leave year" value={roll.year} onChange={(e) => setRoll({ year: e.target.value, result: null })} />
                    <Button variant="secondary" onClick={() => runRollover(true)}>Preview</Button>
                    <Button onClick={() => runRollover(false)}>Run</Button>
                </div>
                {rollError && <div className="mt-2"><Alert>{rollError}</Alert></div>}
                {roll.result && (
                    <p className="mt-3 text-sm">
                        {roll.result.dry_run ? 'Would carry' : 'Carried'} <strong>{roll.result.carried}</strong> day(s) and lapse{' '}
                        <strong>{roll.result.lapsed}</strong> across {roll.result.employees} employee(s).
                    </p>
                )}
            </Card>
        </div>
    );
}
