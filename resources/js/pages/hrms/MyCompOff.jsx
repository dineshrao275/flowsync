import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatMinutes } from '../../utils/time';
import { useConfirm } from '../../components/ui/ConfirmDialog';

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
    const { t } = useTranslation();
    usePageTitle(t('hrms.compMine.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();

    const [bank, setBank] = useState(null);
    const [requests, setRequests] = useState(null);
    const [error, setError] = useState(null);
    const [filing, setFiling] = useState(false);
    const [form, setForm] = useState({ from_date: '', to_date: '', reason: '' });
    const [formErrors, setFormErrors] = useState({});
    const [withdrawing, setWithdrawing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.compMine.pageTitle') }]);
    }, [setCrumbs, t]);

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

            setError(t('hrms.compMine.loadFailed'));
        });
    }, [navigate, t]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function submit(e) {
        e.preventDefault();
        setFormErrors({});

        try {
            await api.post('/hrms/comp-off/requests', form);

            toast.success(t('hrms.compMine.requested'));
            setFiling(false);
            setForm({ from_date: '', to_date: '', reason: '' });
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function withdraw(request) {
        if (!(await confirm({
            title: t('hrms.compMine.withdrawTitle'),
            message: t('hrms.compMine.withdrawMessage', { date: request.worked_date }),
            variant: 'danger',
            confirmLabel: t('hrms.compMine.withdrawConfirm'),
        }))) return;

        setWithdrawing(request.id);

        try {
            await api.delete(`/hrms/comp-off/requests/${request.id}`);

            toast.success(t('hrms.compMine.withdrawn'));
            load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? t('hrms.compMine.withdrawFailed'));
        } finally {
            setWithdrawing(null);
        }
    }

    const cancellable = (request) => ['submitted', 'pending', 'approved'].includes(request.status);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.compMine.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {bank ? t('hrms.compMine.balanceFree', { balance: formatMinutes(bank.balance_minutes) }) : '—'}
                    </p>
                </div>

                <Button onClick={() => setFiling(true)}>{t('hrms.compMine.redeem')}</Button>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Card title={t('hrms.compMine.free')} dense>
                    {!bank ? (
                        <ContentSkeleton size="sm" />
                    ) : (
                        <p className="text-2xl font-semibold text-gray-900">{formatMinutes(bank.balance_minutes)}</p>
                    )}
                </Card>
                <Card title={t('hrms.compMine.expiring')} dense>
                    {!bank ? (
                        <ContentSkeleton size="sm" />
                    ) : (
                        <p className="text-2xl font-semibold text-amber-600">{formatMinutes(bank.expiring_minutes)}</p>
                    )}
                </Card>
                <Card title={t('hrms.compMine.expired')} dense>
                    {!bank ? (
                        <ContentSkeleton size="sm" />
                    ) : (
                        <p className="text-2xl font-semibold text-gray-400">{formatMinutes(bank.expired_minutes)}</p>
                    )}
                </Card>
            </div>

            <Card title={t('hrms.compMine.banked')} dense>
                {!bank ? (
                    <ContentSkeleton size="sm" />
                ) : bank.credits.length === 0 ? (
                    <EmptyState title={t('hrms.compMine.emptyBank')} description={t('hrms.compMine.emptyBankHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.compMine.thDate')}</Th>
                                <Th>{t('hrms.compMine.thSource')}</Th>
                                <Th>{t('hrms.compMine.thMinutes')}</Th>
                                <Th>{t('hrms.compMine.thExpires')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {bank.credits.map((credit) => (
                                <tr key={credit.id}>
                                    <Td>{credit.work_date}</Td>
                                    <Td>{credit.source_label}</Td>
                                    <Td>{formatMinutes(credit.minutes)}</Td>
                                    <Td>{credit.expiry_date ?? t('hrms.compMine.never')}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card title={t('hrms.compMine.history')} dense>
                {!requests ? (
                    <ContentSkeleton size="sm" />
                ) : requests.length === 0 ? (
                    <EmptyState title={t('hrms.compMine.historyEmpty')} description={t('hrms.compMine.historyHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('hrms.compMine.thDates')}</Th>
                                <Th>{t('hrms.compMine.thMinutes')}</Th>
                                <Th>{t('hrms.compMine.thStatus')}</Th>
                                <Th><span className="sr-only">{t('hrms.compMine.thActions')}</span></Th>
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
                                                    onClick={() => withdraw(request)}
                                                >
                                                    {withdrawing === request.id ? t('hrms.compMine.withdrawing') : t('hrms.compMine.withdrawConfirm')}
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

            <Modal open={filing} onClose={() => setFiling(false)} title={t('hrms.compMine.redeemTitle')}>
                <form onSubmit={submit} className="space-y-3 px-6 py-5">
                    <div className="grid grid-cols-2 gap-3">
                        <Input type="date" label={t('hrms.compMine.from')} value={form.from_date} onChange={(e) => setForm({ ...form, from_date: e.target.value })} error={formErrors.from_date} />
                        <Input type="date" label={t('hrms.compMine.to')} value={form.to_date} onChange={(e) => setForm({ ...form, to_date: e.target.value })} error={formErrors.to_date} />
                    </div>
                    <Input label={t('hrms.compMine.reason')} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={formErrors.reason} placeholder={t('hrms.compMine.reasonPlaceholder')} />
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setFiling(false)}>{t('hrms.compMine.cancel')}</Button>
                        <Button type="submit">{t('hrms.compMine.send')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
