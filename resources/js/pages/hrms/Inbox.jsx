import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Pagination from '../../components/ui/Pagination';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { useAuth } from '../../context/AuthContext';
import { payrollRunUrl } from '../../utils/deepLinks';

const TYPE_KEYS = {
    approval: 'approval',
    case_task: 'case_task',
    regularization: 'regularization',
    document_request: 'document_request',
    asset: 'asset',
    document_expiring: 'document_expiring',
    payslip_dispute: 'payslip_dispute',
};

/**
 * One place to work: every pending approval and assigned action item,
 * grouped by kind with priority order kept inside each group.
 *
 * The server sends ids, never URLs — this page builds every href from
 * `meta` like the notification list does, so a queue item can never
 * smuggle a destination past the router. Corrections decide inline
 * (approve/reject live here); everything else navigates to the surface
 * that owns it. Marking read only hides: the underlying row still has to
 * be worked wherever it lives.
 */
export default function Inbox() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.inbox.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();

    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.inbox.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(
        (targetPage) => {
            setError(null);

            return api
                .get('/hrms/inbox', { params: { page: targetPage, per_page: 20 } })
                .then(({ data: response }) => setData(response))
                .catch((err) => {
                    if (err.response?.status === 403) {
                        navigate('/403', { replace: true });
                        return;
                    }

                    setError(t('hrms.inbox.loadFailed'));
                });
        },
        [navigate, t],
    );

    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => {
        load(page);
    }, [page, load]);

    async function markRead(item) {
        try {
            await api.post('/hrms/inbox/read', { keys: [item.key] });
            load(page);
        } catch {
            setError(t('hrms.inbox.markReadFailed'));
        }
    }

    async function markAllRead() {
        try {
            await api.post('/hrms/inbox/read-all', {});
            toast.success(t('hrms.inbox.cleared'));
            load(1);
            setPage(1);
        } catch {
            setError(t('hrms.inbox.clearFailed'));
        }
    }

    async function decideRegularization(item, verdict) {
        try {
            await api.post(`/hrms/attendance/regularizations/${item.source_id}/${verdict}`, {});
            toast.success(verdict === 'approve' ? t('hrms.inbox.approved') : t('hrms.inbox.rejected'));
            load(page);
        } catch {
            setError(t('hrms.inbox.decideFailed'));
        }
    }

    function hrefFor(item) {
        const meta = item.meta ?? {};

        switch (item.type) {
            case 'approval': {
                const action = meta.action ?? '';
                if (action.startsWith('leave.')) return '/hrms/leave';
                if (action.startsWith('expense.')) return '/hrms/expenses';
                if (action.startsWith('compensation.')) return '/hrms/compensation';
                return '/hrms/inbox';
            }
            case 'case_task':
                return meta.kind === 'offboarding'
                    ? `/hrms/offboarding/cases/${meta.case_id}`
                    : `/hrms/onboarding/cases/${meta.case_id}`;
            case 'regularization':
                return '/hrms/attendance/approvals';
            case 'document_request':
                return '/hrms/documents/mine';
            case 'asset':
                return '/hrms/assets/mine';
            case 'document_expiring':
                return '/hrms/documents';
            case 'payslip_dispute':
                return can('hrms.payroll.run') && meta.payroll_run_id
                    ? payrollRunUrl(meta.payroll_run_id)
                    : '/hrms/payroll/mine';
            default:
                return '/hrms/inbox';
        }
    }

    function open(item) {
        markRead(item).then(() => navigate(hrefFor(item)));
    }

    const rows = data?.items ?? null;
    const groups = (rows ?? []).reduce((acc, item) => {
        (acc[item.type] ??= []).push(item);
        return acc;
    }, {});

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.inbox.title')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? t('hrms.inbox.unread', { count: data.unread_count }) : '—'}
                    </p>
                </div>
                {(data?.unread_count ?? 0) > 0 && (
                    <Button variant="secondary" onClick={markAllRead}>{t('hrms.inbox.markAll')}</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {!rows ? (
                <ContentSkeleton size="md" />
            ) : rows.length === 0 ? (
                <EmptyState title={t('hrms.inbox.emptyTitle')} hint={t('hrms.inbox.emptyHint')} />
            ) : (
                Object.entries(groups).map(([type, items]) => (
                    <Card key={type} title={TYPE_KEYS[type] ? t(`hrms.inbox.types.${TYPE_KEYS[type]}`) : type} dense>
                        <ul className="divide-y divide-gray-100">
                            {items.map((item) => (
                                <li key={item.key} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                    <button type="button" onClick={() => open(item)} className="min-w-0 flex-1 text-left">
                                        <span className="block truncate text-sm font-medium text-gray-900">
                                            {item.priority === 'high' && <span className="mr-1.5 inline-block h-2 w-2 rounded-full bg-red-500" aria-label={t('hrms.inbox.highPriority')} />}
                                            {item.title}
                                        </span>
                                        <span className="block truncate text-xs text-gray-400">
                                            {[item.subtitle, item.due_at ? t('hrms.inbox.due', { date: item.due_at }) : null].filter(Boolean).join(' · ')}
                                        </span>
                                    </button>
                                    <span className="flex shrink-0 gap-2">
                                        {item.type === 'regularization' && (
                                            <>
                                                <Button size="sm" variant="secondary" onClick={() => decideRegularization(item, 'approve')}>{t('hrms.inbox.approve')}</Button>
                                                <Button size="sm" variant="danger" onClick={() => decideRegularization(item, 'reject')}>{t('hrms.inbox.reject')}</Button>
                                            </>
                                        )}
                                        <Button size="sm" variant="ghost" onClick={() => markRead(item)}>{t('hrms.inbox.dismiss')}</Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ))
            )}

            {data && (
                <Pagination
                    page={data.pagination.current_page}
                    pages={data.pagination.last_page}
                    total={data.pagination.total}
                    onChange={setPage}
                />
            )}
        </div>
    );
}
