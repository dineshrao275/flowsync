import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import LeaveBalanceTable from '../../components/hrms/LeaveBalanceTable';
import LeaveRequestModal from '../../components/hrms/LeaveRequestModal';
import { useConfirm } from '../../components/ui/ConfirmDialog';

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
    const { t } = useTranslation();
    usePageTitle(t('hrms.myLeave.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();

    const [balances, setBalances] = useState(null);
    const [requests, setRequests] = useState(null);
    const [types, setTypes] = useState([]);
    const [error, setError] = useState(null);
    const [filing, setFiling] = useState(false);
    const [withdrawing, setWithdrawing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.myLeave.pageTitle') }]);
    }, [setCrumbs, t]);

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

            setError(t('hrms.myLeave.loadFailed'));
        });
    }, [navigate, t]);

    useEffect(() => {
        load();

        api.get('/hrms/leave/types')
            .then(({ data }) => setTypes(data.leave_types ?? []))
            .catch(() => setTypes([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function withdraw(request) {
        if (!(await confirm({
            title: t('hrms.myLeave.withdrawTitle'),
            message: t('hrms.myLeave.withdrawMessage', { from: request.from_date, to: request.to_date }),
            variant: 'danger',
            confirmLabel: t('hrms.myLeave.withdrawConfirm'),
        }))) return;

        setWithdrawing(request.id);

        try {
            await api.delete(`/hrms/leave/requests/${request.id}`);

            toast.success(t('hrms.myLeave.withdrawn'));
            load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? t('hrms.myLeave.withdrawFailed'));
        } finally {
            setWithdrawing(null);
        }
    }

    const cancellable = (request) => ['submitted', 'pending', 'approved'].includes(request.status);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.myLeave.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {requests ? (requests.length === 1 ? t('hrms.myLeave.requestsOne') : t('hrms.myLeave.requests', { count: requests.length })) : '—'}
                    </p>
                </div>

                <Button onClick={() => setFiling(true)}>{t('hrms.myLeave.requestLeave')}</Button>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card title={t('hrms.myLeave.balances')} dense>
                {!balances ? (
                    <ContentSkeleton size="sm" />
                ) : balances.length === 0 ? (
                    <EmptyState title={t('hrms.myLeave.balancesEmpty')} description={t('hrms.myLeave.balancesHint')} />
                ) : (
                    <LeaveBalanceTable balances={balances} />
                )}
            </Card>

            <Card title={t('hrms.myLeave.history')} dense>
                {!requests ? (
                    <ContentSkeleton size="sm" />
                ) : requests.length === 0 ? (
                    <EmptyState title={t('hrms.myLeave.historyEmpty')} description={t('hrms.myLeave.historyHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.myLeave.thDates')}</Th>
                                <Th>{t('hrms.myLeave.thType')}</Th>
                                <Th>{t('hrms.myLeave.thDays')}</Th>
                                <Th>{t('hrms.myLeave.thStatus')}</Th>
                                <Th><span className="sr-only">{t('hrms.myLeave.thActions')}</span></Th>
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
                                                    onClick={() => withdraw(request)}
                                                >
                                                    {withdrawing === request.id ? t('hrms.myLeave.withdrawing') : t('hrms.myLeave.withdrawConfirm')}
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
