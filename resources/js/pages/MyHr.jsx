import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Card from '../components/ui/Card';
import EmptyState from '../components/ui/EmptyState';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import ClockInWidget from '../components/hrms/ClockInWidget';

/**
 * My HR home: one screen from the single `/api/my/hr` request — quick
 * actions, balances, upcoming leave, clock-in, pending queues, hardware,
 * files, pay, goals and cases.
 *
 * Sections render what the payload carries and skip what it does not: a
 * record-less login reads an empty home from the backend, so this page
 * shows the empty state instead of a wall of loaders. The clock card
 * owns its own today-state (the aggregate carries the month, not the
 * punches), and hides entirely where the attendance module is off.
 */
export default function MyHr() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.home.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [home, setHome] = useState(null);
    const [error, setError] = useState(null);

    const [todayData, setTodayData] = useState(null);
    const [clockOn, setClockOn] = useState(true);
    const [punching, setPunching] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.home.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/my/hr')
            .then(({ data }) => setHome(data))
            .catch((err) => {
                if (err.response?.status === 403 || err.response?.status === 404) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.home.loadFailed'));
            });
    }, [navigate, t]);

    const loadToday = useCallback(() => {
        return api
            .get('/hrms/attendance/today')
            .then(({ data }) => setTodayData(data))
            .catch(() => setClockOn(false));
    }, []);

    useEffect(() => {
        load();
        loadToday();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function punch(direction) {
        setPunching(true);

        try {
            await api.post('/hrms/attendance/punch', { direction });
            toast.success(direction === 'in' ? t('hrms.home.clockedIn') : t('hrms.home.clockedOut'));
            loadToday();
            load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? t('hrms.home.punchFailed'));
        } finally {
            setPunching(false);
        }
    }

    if (!home && !error) {
        return (
            <div className="space-y-4">
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.home.pageTitle')}</h2>
                <ContentSkeleton size="md" />
            </div>
        );
    }

    if (error) {
        return (
            <div className="space-y-4">
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.home.pageTitle')}</h2>
                <Alert>{error}</Alert>
            </div>
        );
    }

    if (!home.profile) {
        return (
            <div className="space-y-4">
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.home.pageTitle')}</h2>
                <EmptyState title={t('hrms.home.emptyTitle')} hint={t('hrms.home.emptyHint')} />
            </div>
        );
    }

    const sections = home.sections ?? {};
    const leave = sections.leave ?? {};
    const requests = sections.requests ?? {};
    const documents = sections.documents ?? {};
    const payroll = sections.payroll ?? {};
    const performance = sections.performance ?? {};
    const cases = sections.cases ?? [];

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{home.profile.name?.split(' ')[0] ? t('hrms.home.greeting', { name: home.profile.name.split(' ')[0] }) : t('hrms.home.greetingFallback')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {[home.profile.designation, home.profile.department].filter(Boolean).join(' · ') || home.profile.employee_code}
                </p>
            </div>

            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                {(home.quick_actions ?? []).map((action) => (
                    <Link
                        key={action.key}
                        to={action.href}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-medium text-gray-800 transition hover:border-[var(--accent-ring)] hover:bg-[var(--accent-soft)]"
                    >
                        {action.label}
                    </Link>
                ))}
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {clockOn && (
                    <ClockInWidget today={todayData} punching={punching} onPunch={punch} />
                )}

                <Card title={t('hrms.home.leave')} dense>
                    {(leave.balances ?? []).length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.home.noBalances')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 text-sm">
                            {leave.balances.map((row, i) => (
                                <li key={i} className="flex justify-between py-1.5">
                                    <span className="text-gray-600">{row.type ?? t('hrms.home.leave')}</span>
                                    <span className="font-medium">{t('hrms.home.days', { count: row.balance })}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {(leave.upcoming ?? []).length > 0 && (
                        <p className="mt-2 text-xs text-gray-400">
                            {t('hrms.home.upcoming', { items: leave.upcoming.map((u) => `${u.type ?? 'leave'} ${u.from_date} → ${u.to_date}`).join('; ') })}
                        </p>
                    )}
                </Card>

                <Card
                    title={t('hrms.home.inbox', { count: sections.inbox_count ?? 0 })}
                    dense
                    actions={<Link to="/hrms/inbox" className="text-sm font-medium text-[var(--accent-soft-text)] hover:underline">{t('hrms.home.openInbox')}</Link>}
                >
                    {(requests.leave ?? []).length + (requests.expenses ?? []).length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.home.nothingWaiting')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 text-sm">
                            {(requests.leave ?? []).map((r) => (
                                <li key={`leave-${r.id}`} className="py-1.5">Leave {r.from_date} → {r.to_date} · {r.status}</li>
                            ))}
                            {(requests.expenses ?? []).map((r) => (
                                <li key={`expense-${r.id}`} className="py-1.5">{r.claim_number} · {r.total_amount} · {r.status}</li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card
                    title={t('hrms.home.pay')}
                    dense
                    actions={<Link to="/hrms/payroll/mine" className="text-sm font-medium text-[var(--accent-soft-text)] hover:underline">{t('hrms.home.myPayslips')}</Link>}
                >
                    {payroll.latest ? (
                        <p className="text-sm text-gray-600">
                            {t('hrms.home.latest', { period: payroll.latest.period, status: payroll.latest.status })}
                            {payroll.next_pay_date ? <span className="block text-xs text-gray-400">{t('hrms.home.nextPay', { date: payroll.next_pay_date })}</span> : null}
                        </p>
                    ) : (
                        <p className="text-sm text-gray-500">{t('hrms.home.noPayslip')}</p>
                    )}
                </Card>

                <Card title={t('hrms.home.hardware')} dense>
                    {(sections.assets ?? []).length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.home.nothingOut')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 text-sm">
                            {sections.assets.map((a, i) => (
                                <li key={i} className="py-1.5">{a.asset_code} · {a.name}{a.acknowledged ? '' : t('hrms.home.awaitingSignature')}</li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title={t('hrms.home.files')} dense>
                    {(documents.expiring ?? []).length === 0 && (documents.pending_asks ?? 0) === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.home.filesOk')}</p>
                    ) : (
                        <div className="text-sm text-gray-600">
                            {(documents.expiring ?? []).map((d) => (
                                <p key={d.id}>{t('hrms.home.fileExpires', { title: d.title, date: d.expires_at })}</p>
                            ))}
                            {(documents.pending_asks ?? 0) > 0 && <p>{t('hrms.home.fileAsks', { count: documents.pending_asks })}</p>}
                        </div>
                    )}
                </Card>

                <Card title={t('hrms.home.goals')} dense>
                    {(performance.goals ?? []).length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.home.noGoals')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 text-sm">
                            {performance.goals.map((g) => (
                                <li key={g.id} className="flex justify-between py-1.5">
                                    <span className="text-gray-600">{g.title}</span>
                                    <span className="font-medium">{g.progress_percent}%</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {performance.next_one_on_one && (
                        <p className="mt-2 text-xs text-gray-400">{t('hrms.home.nextOneOnOne', { at: performance.next_one_on_one.scheduled_at })}</p>
                    )}
                </Card>

                {cases.length > 0 && (
                    <Card title={t('hrms.home.openItems')} dense>
                        <ul className="divide-y divide-gray-100 text-sm">
                            {cases.map((c) => (
                                <li key={`${c.kind}-${c.id}`} className="py-1.5">
                                    {c.title}
                                    <span className="ml-2 text-xs text-gray-400">{c.kind}{c.due_date ? t('hrms.home.caseDue', { date: c.due_date }) : ''}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>

            <div className="flex gap-2">
                <Button variant="secondary" onClick={() => { load(); loadToday(); }}>{t('hrms.home.refresh')}</Button>
            </div>
        </div>
    );
}
