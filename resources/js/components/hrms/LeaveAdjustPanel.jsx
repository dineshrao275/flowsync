import { useCallback, useEffect, useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Card from '../ui/Card';
import Input from '../ui/Input';
import Select from '../ui/Select';
import { Table, Th, Td, TableEmpty } from '../ui/Table';
import { useToast } from '../../context/ToastContext';
import Timestamp from '../ui/Timestamp';

/**
 * Leave admin tools: a signed, reasoned balance correction, the ledger behind
 * a person's balance, and the year-end carry-forward/lapse run (with a dry
 * run). Every call needs `hrms.leave.manage` (the backend 403s regardless).
 */
export default function LeaveAdjustPanel({ employees, types }) {
    const { t } = useTranslation();
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
            toast.success(t('hrms.leaveAdjust.adjusted'));
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
            setRollError(fieldErrors(err).year ?? t('hrms.leaveAdjust.rolloverFailed'));
        }
    }

    return (
        <div className="space-y-4">
            <Card dense title={t('hrms.leaveAdjust.title')}>
                <form onSubmit={adjust} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Select label={t('hrms.leaveAdjust.employee')} value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })} error={errors.employee_id}>
                        <option value="">{t('hrms.leaveAdjust.choose')}</option>
                        {employees.map((emp) => <option key={emp.id} value={emp.id}>{emp.name}</option>)}
                    </Select>
                    <Select label={t('hrms.leaveAdjust.leaveType')} value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })} error={errors.leave_type_id}>
                        <option value="">{t('hrms.leaveAdjust.choose')}</option>
                        {(types ?? []).map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                    </Select>
                    <Input label={t('hrms.leaveAdjust.year')} value={form.year} onChange={(e) => setForm({ ...form, year: e.target.value })} error={errors.year} />
                    <Input type="number" step="0.5" label={t('hrms.leaveAdjust.quantity')} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} error={errors.quantity} />
                    <Input label={t('hrms.leaveAdjust.reason')} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={errors.reason} className="sm:col-span-2" />
                    <div className="sm:col-span-2"><Button type="submit">{t('hrms.leaveAdjust.applyAdjustment')}</Button></div>
                </form>
            </Card>

            <Card dense title={t('hrms.leaveAdjust.ledger')}>
                {!form.employee_id ? (
                    <p className="text-sm text-gray-500">{t('hrms.leaveAdjust.pickEmployee')}</p>
                ) : (
                    <Table compact>
                        <thead>
                            <tr><Th>{t('hrms.leaveAdjust.thWhen')}</Th><Th>{t('hrms.leaveAdjust.thType')}</Th><Th>{t('hrms.leaveAdjust.thKind')}</Th><Th align="right">{t('hrms.leaveAdjust.thDays')}</Th><Th>{t('hrms.leaveAdjust.thNote')}</Th><Th>{t('hrms.leaveAdjust.thBy')}</Th></tr>
                        </thead>
                        <tbody>
                            {(ledger ?? []).map((row) => (
                                <tr key={row.id}>
                                    <Td><Timestamp date={row.created_at} /></Td>
                                    <Td>{row.leave_type}</Td>
                                    <Td>{row.kind_label}</Td>
                                    <Td align="right">{row.quantity > 0 ? `+${row.quantity}` : row.quantity}</Td>
                                    <Td>{row.note}</Td>
                                    <Td>{row.actor ?? t('hrms.leaveAdjust.system')}</Td>
                                </tr>
                            ))}
                            {ledger && ledger.length === 0 && <TableEmpty colSpan={6}>{t('hrms.leaveAdjust.emptyLedger')}</TableEmpty>}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card dense title={t('hrms.leaveAdjust.rolloverTitle')}>
                <p className="mb-3 text-sm text-gray-500">
                    {t('hrms.leaveAdjust.rolloverIntro')}
                </p>
                <div className="flex flex-wrap items-end gap-2">
                    <Input label={t('hrms.leaveAdjust.closedYear')} value={roll.year} onChange={(e) => setRoll({ year: e.target.value, result: null })} />
                    <Button variant="secondary" onClick={() => runRollover(true)}>{t('hrms.leaveAdjust.preview')}</Button>
                    <Button onClick={() => runRollover(false)}>{t('hrms.leaveAdjust.run')}</Button>
                </div>
                {rollError && <div className="mt-2"><Alert>{rollError}</Alert></div>}
                {roll.result && (
                    <p className="mt-3 text-sm">
                        <Trans
                            i18nKey={roll.result.dry_run ? 'hrms.leaveAdjust.carryPreview' : 'hrms.leaveAdjust.carriedResult'}
                            values={{ carried: roll.result.carried, lapsed: roll.result.lapsed, employees: roll.result.employees }}
                            components={[<strong key="0" />, <strong key="1" />]}
                        />
                    </p>
                )}
            </Card>
        </div>
    );
}
