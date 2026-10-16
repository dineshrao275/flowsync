import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Input from '../ui/Input';
import Select from '../ui/Select';
import { Table, Th, Td, TableEmpty } from '../ui/Table';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../ui/ConfirmDialog';

const empty = { name: '', from_date: '', to_date: '', leave_type_id: '', department_id: '', reason: '' };

/**
 * Leave blackout windows: date ranges (company-wide, or narrowed to a leave
 * type) in which leave cannot be asked for. Managers add and remove them.
 */
export default function LeaveBlackoutPanel({ types }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
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
            toast.success(t('hrms.leaveBlackout.created'));
            setForm(empty);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function remove(id) {
        if (!(await confirm({ title: t('hrms.leaveBlackout.deleteTitle'), message: t('hrms.leaveBlackout.deleteMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/leave/blackouts/${id}`);
            load();
        } catch {
            toast.error(t('hrms.leaveBlackout.deleteFailed'));
        }
    }

    return (
        <div className="space-y-4">
            <Card dense title={t('hrms.leaveBlackout.title')}>
                <Table>
                    <thead>
                        <tr><Th>{t('hrms.leaveBlackout.thName')}</Th><Th>{t('hrms.leaveBlackout.thFrom')}</Th><Th>{t('hrms.leaveBlackout.thTo')}</Th><Th>{t('hrms.leaveBlackout.thApplies')}</Th><Th><span className="sr-only">{t('hrms.leaveBlackout.thActions')}</span></Th></tr>
                    </thead>
                    <tbody>
                        {(rows ?? []).map((row) => (
                            <tr key={row.id}>
                                <Td>{row.name}</Td>
                                <Td>{row.from_date}</Td>
                                <Td>{row.to_date}</Td>
                                <Td>{[row.leave_type ?? t('hrms.leaveBlackout.allTypes'), row.department ?? t('hrms.leaveBlackout.everyone')].join(' · ')}</Td>
                                <Td><div className="flex justify-end"><Button variant="secondary" onClick={() => remove(row.id)}>{t('hrms.leaveBlackout.delete')}</Button></div></Td>
                            </tr>
                        ))}
                        {rows && rows.length === 0 && <TableEmpty colSpan={5}>{t('hrms.leaveBlackout.empty')}</TableEmpty>}
                    </tbody>
                </Table>
            </Card>

            <Card dense title={t('hrms.leaveBlackout.newTitle')}>
                <form onSubmit={save} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Input label={t('hrms.leaveBlackout.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} />
                    <Select label={t('hrms.leaveBlackout.leaveType')} value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })} error={errors.leave_type_id}>
                        <option value="">{t('hrms.leaveBlackout.allTypes')}</option>
                        {(types ?? []).map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                    </Select>
                    <Input type="date" label={t('hrms.leaveBlackout.from')} value={form.from_date} onChange={(e) => setForm({ ...form, from_date: e.target.value })} error={errors.from_date} />
                    <Input type="date" label={t('hrms.leaveBlackout.to')} value={form.to_date} onChange={(e) => setForm({ ...form, to_date: e.target.value })} error={errors.to_date} />
                    <Input label={t('hrms.leaveBlackout.reason')} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={errors.reason} className="sm:col-span-2" />
                    <div className="sm:col-span-2"><Button type="submit">{t('hrms.leaveBlackout.create')}</Button></div>
                </form>
            </Card>
        </div>
    );
}
