import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { employeeUrl } from '../../utils/deepLinks';

/**
 * The manager's telescope: one row per direct report with presence,
 * leave-today, overdue work, waiting approvals and balances.
 *
 * Read-only by construction — this page links out (profile, queues,
 * tasks) but edits nothing, because a telescope that also writes is a
 * second set of mutation paths for every module it looks at. The date
 * window scopes the attendance summary; it never touches pay, which the
 * backend omits from the payload entirely.
 */
export default function MyTeam() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.team.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();

    const [reports, setReports] = useState(null);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.team.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        const params = Object.fromEntries([['from', from], ['to', to]].filter(([, v]) => v !== ''));

        return api
            .get('/my/team', { params })
            .then(({ data }) => setReports(data.reports ?? []))
            .catch((err) => {
                if (err.response?.status === 403 || err.response?.status === 404) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.team.loadFailed'));
            });
    }, [from, to, navigate, t]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.team.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {reports ? (reports.length === 1 ? t('hrms.team.reportsOne') : t('hrms.team.reports', { count: reports.length })) : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Input label={t('hrms.team.from')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    <Input label={t('hrms.team.to')} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    <div className="flex items-end"><Button variant="secondary" onClick={load}>{t('hrms.team.apply')}</Button></div>
                </div>
            </Card>

            {!reports ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : reports.length === 0 ? (
                <EmptyState title={t('hrms.team.emptyTitle')} hint={t('hrms.team.emptyHint')} />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>{t('hrms.team.thPerson')}</Th><Th>{t('hrms.team.thToday')}</Th><Th>{t('hrms.team.thLeave')}</Th><Th>{t('hrms.team.thOverdue')}</Th><Th>{t('hrms.team.thApprovals')}</Th><Th>{t('hrms.team.thBalance')}</Th></tr>
                        </thead>
                        <tbody>
                            {reports.map((report) => (
                                <tr key={report.id}>
                                    <Td>
                                        <Link to={employeeUrl(report.id)} className="font-medium text-[var(--accent-soft-text)] hover:underline">
                                            {report.name}
                                        </Link>
                                        <span className="block text-xs text-gray-400">{report.department ?? report.status}</span>
                                    </Td>
                                    <Td>{report.today}{report.on_leave_today ? t('hrms.team.onLeave') : ''}</Td>
                                    <Td>
                                        {(report.leave_balances ?? []).map((b, i) => (
                                            <span key={i} className="mr-2">{b.type}: {b.balance}</span>
                                        ))}
                                    </Td>
                                    <Td>{(report.overdue_tasks ?? []).length}</Td>
                                    <Td>{(report.pending_approvals ?? []).length}</Td>
                                    <Td>
                                        {(report.overdue_tasks ?? []).slice(0, 2).map((t) => (
                                            <span key={t.id} className="block text-xs text-gray-500">{t.key} · {t.title}</span>
                                        ))}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}
        </div>
    );
}
