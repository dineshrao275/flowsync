import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
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
    usePageTitle('My team');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();

    const [reports, setReports] = useState(null);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My team' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        const params = Object.fromEntries([['from', from], ['to', to]].filter(([, v]) => v !== ''));

        return api
            .get('/api/my/team', { params })
            .then(({ data }) => setReports(data.reports ?? []))
            .catch((err) => {
                if (err.response?.status === 403 || err.response?.status === 404) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load your team.');
            });
    }, [from, to, navigate]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">My team</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {reports ? `${reports.length} direct report${reports.length === 1 ? '' : 's'}` : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    <div className="flex items-end"><Button variant="secondary" onClick={load}>Apply window</Button></div>
                </div>
            </Card>

            {!reports ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : reports.length === 0 ? (
                <EmptyState title="No direct reports" hint="Anyone reporting to you appears here." />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>Person</Th><Th>Today</Th><Th>Leave</Th><Th>Overdue</Th><Th>Approvals</Th><Th>Balance</Th></tr>
                        </thead>
                        <tbody>
                            {reports.map((report) => (
                                <tr key={report.id}>
                                    <Td>
                                        <Link to={employeeUrl(report.id)} className="font-medium text-indigo-600 hover:underline">
                                            {report.name}
                                        </Link>
                                        <span className="block text-xs text-gray-400">{report.department ?? report.status}</span>
                                    </Td>
                                    <Td>{report.today}{report.on_leave_today ? ' · on leave' : ''}</Td>
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
