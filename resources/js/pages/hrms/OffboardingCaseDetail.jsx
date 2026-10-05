import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Spinner from '../../components/ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { hrmsUrl } from '../../utils/deepLinks';
import Checklist from '../../components/hrms/Checklist';
import ConvertTaskModal from '../../components/hrms/ConvertTaskModal';

/**
 * One exit run: the clearance sign-off first, the checklist second.
 *
 * The blockers panel sits above everything because this is the screen an HR
 * manager signs an exit on — a blocked exit must read as blocked at a
 * glance, not after scrolling past five completed checklist rows. The Clear
 * button confirms, because a 200 here is a signature.
 */
export default function OffboardingCaseDetail() {
    const { caseId } = useParams();
    const navigate = useNavigate();
    const setCrumbs = useSetCrumbs();
    const { user, can } = useAuth();
    const toast = useToast();

    const [detail, setDetail] = useState(null);
    const [error, setError] = useState(null);
    const [acting, setActing] = useState(false);
    const [converting, setConverting] = useState(null);

    const canManage = can('permission:hrms.offboarding.manage');

    usePageTitle(detail ? `Exit · ${detail.employee?.name ?? ''}` : 'Exit run');

    const load = useCallback(() => {
        setError(null);

        return api
            .get(`/hrms/offboarding/cases/${caseId}`)
            .then(({ data }) => setDetail(data.case))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load this exit run.');
            });
    }, [caseId, navigate]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!detail) return;

        setCrumbs([
            { label: 'HRMS', to: '/hrms' },
            { label: 'Offboarding', to: hrmsUrl('offboarding') },
            { label: detail.employee?.name ?? `Exit ${detail.id}` },
        ]);
    }, [detail, setCrumbs]);

    async function completeTask(task) {
        setActing(true);

        try {
            await api.post(`/hrms/offboarding/cases/${caseId}/tasks/${task.id}/complete`, {});
            await load();
        } catch {
            toast.error('Unable to complete this item.');
        } finally {
            setActing(false);
        }
    }

    async function syncItem(task) {
        setActing(true);

        try {
            const { data } = await api.post(`/hrms/offboarding/cases/${caseId}/tasks/${task.id}/sync`, {});
            toast.success(data.message ?? 'Synced.');
            await load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? 'Unable to sync this item.');
        } finally {
            setActing(false);
        }
    }

    async function clear() {
        if (!window.confirm('Sign off this exit? This records that nothing is outstanding.')) return;

        setActing(true);

        try {
            await api.post(`/hrms/offboarding/cases/${caseId}/clear`, {});
            toast.success('Exit cleared.');
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).form ?? 'Unable to clear this exit.');
        } finally {
            setActing(false);
        }
    }

    async function complete() {
        setActing(true);

        try {
            await api.post(`/hrms/offboarding/cases/${caseId}/complete`, {});
            toast.success('Exit run completed.');
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).form ?? 'Unable to complete this exit.');
        } finally {
            setActing(false);
        }
    }

    async function cancel() {
        if (!window.confirm('Cancel this exit run? The rows stay for the audit trail.')) return;

        setActing(true);

        try {
            await api.post(`/hrms/offboarding/cases/${caseId}/cancel`, {});
            toast.success('Exit run cancelled.');
            await load();
        } catch {
            toast.error('Unable to cancel this exit.');
        } finally {
            setActing(false);
        }
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!detail) {
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );
    }

    const open = detail.status === 'initiated' || detail.status === 'in_progress';
    // The backend’s complete rule, mirrored: the leaver works their own
    // items, HR works anyone’s, nobody else works any.
    const canWork = canManage || (detail.employee?.user_id != null && detail.employee.user_id === user?.id);
    const clearance = detail.clearance ?? {};
    const blockedReasons = clearance.blocked_reasons ?? [];
    const signed = clearance.cleared_at != null;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{detail.employee?.name}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {detail.reason_label ?? detail.reason} · last day {detail.last_working_day} ·{' '}
                        {detail.status_label ?? detail.status}
                    </p>
                </div>

                {canManage && open && (
                    <div className="flex gap-2">
                        {!signed && (
                            <Button loading={acting} onClick={clear}>
                                Clear exit
                            </Button>
                        )}
                        <Button variant="secondary" loading={acting} onClick={complete}>
                            Complete
                        </Button>
                        <Button variant="ghost" loading={acting} onClick={cancel}>
                            Cancel
                        </Button>
                    </div>
                )}
            </div>

            <Card
                title="Clearance"
                dense
                className={signed ? '' : blockedReasons.length > 0 ? 'border-red-300' : ''}
            >
                {signed ? (
                    <p className="text-sm text-green-700">
                        Signed{clearance.cleared_at ? ` on ${new Date(clearance.cleared_at).toLocaleDateString()}` : ''}.
                        Nothing is outstanding.
                    </p>
                ) : blockedReasons.length > 0 ? (
                    <div>
                        <p className="mb-2 text-sm font-semibold text-red-700">
                            This exit cannot be cleared yet:
                        </p>
                        <ul className="list-disc space-y-1 pl-5 text-sm text-red-700">
                            {blockedReasons.map((reason, i) => (
                                <li key={i}>{reason}</li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <p className="text-sm text-gray-500">
                        Nothing is outstanding. Clearing signs the exit off.
                    </p>
                )}
                <dl className="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <Counter label="Assets" value={clearance.pending_assets_count} />
                    <Counter label="Leave days" value={clearance.pending_leave_encashment_days} />
                    <Counter label="Expense" value={clearance.pending_expense_amount} />
                    <Counter label="Documents" value={clearance.pending_documents_count} />
                </dl>
            </Card>

            <Card title="Checklist" dense>
                <Checklist
                    tasks={detail.tasks ?? []}
                    canAct={open && canWork}
                    canWaive={false}
                    onComplete={completeTask}
                    onWaive={null}
                    canConvert={open && canWork}
                    onConvert={setConverting}
                    onSync={syncItem}
                />
            </Card>

            {converting && (
                <ConvertTaskModal
                    caseKind="offboarding"
                    caseId={caseId}
                    item={converting}
                    onClose={() => setConverting(null)}
                    onDone={async () => {
                        setConverting(null);
                        await load();
                    }}
                />
            )}

            {(detail.requests ?? []).length > 0 && (
                <Card title="Document asks" dense>
                    <ul className="divide-y divide-gray-100">
                        {(detail.requests ?? []).map((ask) => (
                            <li key={ask.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium text-gray-900">{ask.title}</span>
                                    <span className="text-xs text-gray-400">
                                        {ask.type?.name ?? 'Any file'}
                                        {ask.due_date ? ` · due ${ask.due_date}` : ''}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs capitalize text-gray-500">{ask.status}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="pt-2 text-sm">
                <Link to={hrmsUrl('offboarding')} className="text-indigo-600 hover:text-indigo-800">
                    ← Back to offboarding
                </Link>
            </div>
        </div>
    );
}

function Counter({ label, value }) {
    return (
        <div className="rounded-lg bg-gray-50 px-3 py-2">
            <dt className="text-xs text-gray-400">{label}</dt>
            <dd className={`text-lg font-semibold ${value > 0 ? 'text-red-600' : 'text-gray-900'}`}>{value ?? 0}</dd>
        </div>
    );
}
