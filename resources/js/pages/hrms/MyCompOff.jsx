import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatMinutes } from '../../utils/time';

/**
 * The caller's own comp-off bank: what was credited, what is free, what is
 * about to expire — plus filing and withdrawing.
 *
 * Self-scoped on purpose — this page needs no comp-off permission because
 * it only ever shows the caller's own rows. Minutes stay minutes on the
 * wire; `formatMinutes` renders them, so sorting never depends on a
 * formatted string.
 */
export default function MyCompOff() {
    usePageTitle('My comp-off');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [bank, setBank] = useState(null);
    const [requests, setRequests] = useState(null);
    const [error, setError] = useState(null);
    const [filing, setFiling] = useState(false);
    const [form, setForm] = useState({ from_date: '', to_date: '', reason: '' });
    const [formErrors, setFormErrors] = useState({});
    const [withdrawing, setWithdrawing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My comp-off' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        return Promise.all([
            api.get('/hrms/comp-off/credits').then(({ data }) => setBank(data)),
            api.get('/hrms/comp-off/requests').then(({ data }) => setRequests(data.requests ?? [])),
        ]).catch((err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError('Unable to load your comp-off.');
        });
    }, [navigate]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function submit(e) {
        e.preventDefault();
        setFormErrors({});

        try {
            await api.post('/hrms/comp-off/requests', form);

            toast.success('Comp-off requested. Your manager will review it.');
            setFiling(false);
            setForm({ from_date: '', to_date: '', reason: '' });
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function withdraw(id) {
        setWithdrawing(id);

        try {
            await api.delete(`/hrms/comp-off/requests/${id}`);

            toast.success('Comp-off request withdrawn.');
            load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? 'Unable to withdraw the request.');
        } finally {
            setWithdrawing(null);
        }
    }

    const cancellable = (request) => ['submitted', 'pending', 'approved'].includes(request.status);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">My comp-off</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {bank ? `${formatMinutes(bank.balance_minutes)} free` : '—'}
                    </p>
                </div>

                <Button onClick={() => setFiling(true)}>Redeem time</Button>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Card title="Free" dense>
                    {!bank ? (
                        <div className="flex justify-center py-4"><Spinner /></div>
                    ) : (
                        <p className="text-2xl font-semibold text-gray-900">{formatMinutes(bank.balance_minutes)}</p>
                    )}
                </Card>
                <Card title="Expiring within 30 days" dense>
                    {!bank ? (
                        <div className="flex justify-center py-4"><Spinner /></div>
                    ) : (
                        <p className="text-2xl font-semibold text-amber-600">{formatMinutes(bank.expiring_minutes)}</p>
                    )}
                </Card>
                <Card title="Expired" dense>
                    {!bank ? (
                        <div className="flex justify-center py-4"><Spinner /></div>
                    ) : (
                        <p className="text-2xl font-semibold text-gray-400">{formatMinutes(bank.expired_minutes)}</p>
                    )}
                </Card>
            </div>

            <Card title="Banked time" dense>
                {!bank ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : bank.credits.length === 0 ? (
                    <EmptyState title="Nothing banked yet" description="Weekend and holiday duty accrues here." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Date</Th>
                                <Th>Source</Th>
                                <Th>Minutes</Th>
                                <Th>Expires</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {bank.credits.map((credit) => (
                                <tr key={credit.id}>
                                    <Td>{credit.work_date}</Td>
                                    <Td>{credit.source_label}</Td>
                                    <Td>{formatMinutes(credit.minutes)}</Td>
                                    <Td>{credit.expiry_date ?? 'Never'}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card title="History" dense>
                {!requests ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : requests.length === 0 ? (
                    <EmptyState title="No requests yet" description="Redeem banked time above." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Dates</Th>
                                <Th>Minutes</Th>
                                <Th>Status</Th>
                                <Th><span className="sr-only">Actions</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.map((request) => (
                                <tr key={request.id}>
                                    <Td>{request.from_date} → {request.to_date}</Td>
                                    <Td>{formatMinutes(request.total_minutes)}</Td>
                                    <Td>{request.status_label}</Td>
                                    <Td>
                                        {cancellable(request) && (
                                            <div className="flex justify-end">
                                                <Button
                                                    variant="secondary"
                                                    disabled={withdrawing === request.id}
                                                    onClick={() => withdraw(request.id)}
                                                >
                                                    {withdrawing === request.id ? 'Withdrawing…' : 'Withdraw'}
                                                </Button>
                                            </div>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Modal open={filing} onClose={() => setFiling(false)} title="Redeem comp-off">
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <Input type="date" label="From" value={form.from_date} onChange={(e) => setForm({ ...form, from_date: e.target.value })} error={formErrors.from_date} />
                        <Input type="date" label="To" value={form.to_date} onChange={(e) => setForm({ ...form, to_date: e.target.value })} error={formErrors.to_date} />
                    </div>
                    <Input label="Reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={formErrors.reason} placeholder="Long weekend…" />
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setFiling(false)}>Cancel</Button>
                        <Button type="submit">Send request</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
