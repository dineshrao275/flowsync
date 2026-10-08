import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Pagination from '../../components/ui/Pagination';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { useAuth } from '../../context/AuthContext';
import { payrollRunUrl } from '../../utils/deepLinks';

const TYPE_LABELS = {
    approval: 'Approvals',
    case_task: 'Checklists',
    regularization: 'Corrections',
    document_request: 'File asks',
    asset: 'Hardware',
    document_expiring: 'Expiring files',
    payslip_dispute: 'Pay disputes',
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
    usePageTitle('Inbox');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();

    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Inbox' }]);
    }, [setCrumbs]);

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

                    setError('Unable to load your inbox.');
                });
        },
        [navigate],
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
            setError('That item could not be marked read.');
        }
    }

    async function markAllRead() {
        try {
            await api.post('/hrms/inbox/read-all', {});
            toast.success('Inbox cleared.');
            load(1);
            setPage(1);
        } catch {
            setError('The inbox could not be cleared.');
        }
    }

    async function decideRegularization(item, verdict) {
        try {
            await api.post(`/hrms/attendance/regularizations/${item.source_id}/${verdict}`, {});
            toast.success(verdict === 'approve' ? 'Correction approved.' : 'Correction rejected.');
            load(page);
        } catch {
            setError('That correction cannot be decided here.');
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
                    <h2 className="text-xl font-semibold text-gray-900">Inbox</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? `${data.unread_count} unread` : '—'}
                    </p>
                </div>
                {(data?.unread_count ?? 0) > 0 && (
                    <Button variant="secondary" onClick={markAllRead}>Mark all read</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {!rows ? (
                <div className="flex justify-center py-10">
                    <Spinner />
                </div>
            ) : rows.length === 0 ? (
                <EmptyState title="All caught up" hint="Approvals and action items land here." />
            ) : (
                Object.entries(groups).map(([type, items]) => (
                    <Card key={type} title={TYPE_LABELS[type] ?? type} dense>
                        <ul className="divide-y divide-gray-100">
                            {items.map((item) => (
                                <li key={item.key} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                    <button type="button" onClick={() => open(item)} className="min-w-0 flex-1 text-left">
                                        <span className="block truncate text-sm font-medium text-gray-900">
                                            {item.priority === 'high' && <span className="mr-1.5 inline-block h-2 w-2 rounded-full bg-red-500" aria-label="high priority" />}
                                            {item.title}
                                        </span>
                                        <span className="block truncate text-xs text-gray-400">
                                            {[item.subtitle, item.due_at ? `due ${item.due_at}` : null].filter(Boolean).join(' · ')}
                                        </span>
                                    </button>
                                    <span className="flex shrink-0 gap-2">
                                        {item.type === 'regularization' && (
                                            <>
                                                <Button size="sm" variant="secondary" onClick={() => decideRegularization(item, 'approve')}>Approve</Button>
                                                <Button size="sm" variant="danger" onClick={() => decideRegularization(item, 'reject')}>Reject</Button>
                                            </>
                                        )}
                                        <Button size="sm" variant="ghost" onClick={() => markRead(item)}>Dismiss</Button>
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
