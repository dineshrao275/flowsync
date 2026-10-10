import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import LeaveBalanceTable from '../../components/hrms/LeaveBalanceTable';
import LeaveRequestModal from '../../components/hrms/LeaveRequestModal';

/**
 * The caller's own leave: balances, history, filing, withdrawing.
 *
 * Self-scoped on purpose — this page needs no leave permission because it
 * only ever shows the caller's own rows. Withdrawing a pending ask cancels
 * it; withdrawing an approved one returns the time through the same
 * reversal the backend applies, and the history shows the status either
 * way.
 */
export default function MyLeave() {
    usePageTitle('My leave');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [balances, setBalances] = useState(null);
    const [requests, setRequests] = useState(null);
    const [types, setTypes] = useState([]);
    const [error, setError] = useState(null);
    const [filing, setFiling] = useState(false);
    const [withdrawing, setWithdrawing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My leave' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        return Promise.all([
            api.get('/hrms/leave/balances').then(({ data }) => setBalances(data.balances ?? [])),
            api.get('/hrms/leave/requests').then(({ data }) => setRequests(data.requests ?? [])),
        ]).catch((err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError('Unable to load your leave.');
        });
    }, [navigate]);

    useEffect(() => {
        load();

        api.get('/hrms/leave/types')
            .then(({ data }) => setTypes(data.leave_types ?? []))
            .catch(() => setTypes([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function withdraw(id) {
        setWithdrawing(id);

        try {
            await api.delete(`/hrms/leave/requests/${id}`);

            toast.success('Leave request withdrawn.');
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
                    <h2 className="text-[#1C1917] dark:text-[#F8FAFC]">My leave</h2>
                    <p className="mt-0.5 text-[#78716C] dark:text-[#94A3B8]">
                        {requests ? `${requests.length} request${requests.length === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>

                <Button onClick={() => setFiling(true)}>Request leave</Button>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card title="Balances" dense>
                {!balances ? (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                ) : balances.length === 0 ? (
                    <EmptyState title="No balances yet" description="Balances appear once leave is granted or accrued." />
                ) : (
                    <LeaveBalanceTable balances={balances} />
                )}
            </Card>

            <Card title="History" dense>
                {!requests ? (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                ) : requests.length === 0 ? (
                    <EmptyState title="No requests yet" description="File your first leave request above." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Dates</Th>
                                <Th>Type</Th>
                                <Th>Days</Th>
                                <Th>Status</Th>
                                <Th><span className="sr-only">Actions</span></Th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.map((request) => (
                                <tr key={request.id}>
                                    <Td>{request.from_date} → {request.to_date}</Td>
                                    <Td>{request.type?.name ?? '—'}</Td>
                                    <Td>{request.total_days}</Td>
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

            <LeaveRequestModal
                open={filing}
                onClose={() => setFiling(false)}
                types={types}
                onSaved={() => {
                    setFiling(false);
                    load();
                }}
            />
        </div>
    );
}
