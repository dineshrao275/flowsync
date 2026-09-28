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

const REQUEST_COLORS = {
    pending: '#f59e0b',
    submitted: '#0ea5e9',
    accepted: '#10b981',
    waived: '#8b5cf6',
    rejected: '#ef4444',
};

/**
 * One onboarding run: the checklist, the document asks it raised, and the
 * button that closes the case once every mandatory item is resolved.
 *
 * A pending ask carries an inline file picker for the hire (or HR filing on
 * their behalf): the file uploads through the document endpoint, then the
 * returned id submits the ask — two calls, because storing bytes and
 * answering a question are two different writes and the second must not run
 * when the first fails.
 */
export default function OnboardingCaseDetail() {
    const { caseId } = useParams();
    const navigate = useNavigate();
    const setCrumbs = useSetCrumbs();
    const { user, can } = useAuth();
    const toast = useToast();

    const [detail, setDetail] = useState(null);
    const [error, setError] = useState(null);
    const [acting, setActing] = useState(false);

    const canManage = can('permission:hrms.onboarding.manage');

    usePageTitle(detail ? `Onboarding · ${detail.employee?.name ?? ''}` : 'Onboarding case');

    const load = useCallback(() => {
        setError(null);

        return api
            .get(`/hrms/onboarding/cases/${caseId}`)
            .then(({ data }) => setDetail(data.case))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load this case.');
            });
    }, [caseId, navigate]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!detail) return;

        setCrumbs([
            { label: 'HRMS', to: '/hrms' },
            { label: 'Onboarding', to: hrmsUrl('onboarding') },
            { label: detail.employee?.name ?? `Case ${detail.id}` },
        ]);
    }, [detail, setCrumbs]);

    async function completeTask(task) {
        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/tasks/${task.id}/complete`, {});
            await load();
        } catch {
            toast.error('Unable to complete this item.');
        } finally {
            setActing(false);
        }
    }

    async function waiveTask(task, reason) {
        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/tasks/${task.id}/waive`, { reason });
            toast.success('Item waived.');
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).reason ?? fieldErrors(err).form ?? 'Unable to waive this item.');
        } finally {
            setActing(false);
        }
    }

    async function complete() {
        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/complete`, {});
            toast.success('Onboarding completed.');
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).form ?? 'Unable to complete this case.');
        } finally {
            setActing(false);
        }
    }

    async function cancel() {
        if (!window.confirm('Cancel this onboarding case? The rows stay for the audit trail.')) return;

        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/cancel`, {});
            toast.success('Case cancelled.');
            await load();
        } catch {
            toast.error('Unable to cancel this case.');
        } finally {
            setActing(false);
        }
    }

    async function submitFile(ask, file) {
        if (!file) return;

        setActing(true);

        try {
            const formData = new FormData();

            formData.append('employee_id', detail.employee.id);
            formData.append('title', ask.title);
            formData.append('file', file);

            const { data } = await api.post('/hrms/documents', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            await api.post(`/hrms/document-requests/${ask.id}/submit`, { document_id: data.document.id });
            toast.success('File submitted for review.');
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).file ?? fieldErrors(err).form ?? 'Unable to submit this file.');
        } finally {
            setActing(false);
        }
    }

    async function answerAsk(ask, action, reason = null) {
        setActing(true);

        try {
            await api.post(`/hrms/document-requests/${ask.id}/${action}`, reason ? { reason } : {});
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).form ?? fieldErrors(err).reason ?? 'Unable to answer this ask.');
        } finally {
            setActing(false);
        }
    }

    if (error || (detail === null && error !== null)) {
        return <Alert>{error ?? 'This case could not be found.'}</Alert>;
    }

    if (!detail) {
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );
    }

    const isSelf = detail.employee?.user_id != null && detail.employee.user_id === user?.id;
    const progress = detail.progress ?? {};
    const open = detail.status === 'not_started' || detail.status === 'in_progress';
    // The backend’s participant rule, mirrored: the hire works their own
    // items, HR works anyone’s. Anything finer (per-item ownership) stays a
    // 403, which is the real gate; this only decides what gets drawn.
    const canWork = canManage || isSelf;
    // The backend’s submit rule, mirrored: the hire files their own asks, HR
    // files on anyone’s behalf, nobody else files at all.
    const canSubmit = canManage || isSelf;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{detail.employee?.name}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {detail.template?.name ?? 'No template'} · {detail.status_label ?? detail.status} ·{' '}
                        {progress.percent ?? 0}% complete
                        {progress.mandatory_open > 0 && ` · ${progress.mandatory_open} mandatory open`}
                    </p>
                </div>

                {canManage && open && (
                    <div className="flex gap-2">
                        <Button variant="secondary" loading={acting} onClick={complete}>
                            Complete case
                        </Button>
                        <Button variant="ghost" loading={acting} onClick={cancel}>
                            Cancel
                        </Button>
                    </div>
                )}
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full bg-indigo-500" style={{ width: `${progress.percent ?? 0}%` }} />
            </div>

            <Card title="Checklist" dense>
                <Checklist
                    tasks={detail.tasks ?? []}
                    canAct={open && canWork}
                    canWaive={canManage || isSelf}
                    onComplete={completeTask}
                    onWaive={waiveTask}
                />
            </Card>

            {(detail.requests ?? []).length > 0 && (
                <Card title="Document asks" dense>
                    <ul className="divide-y divide-gray-100">
                        {(detail.requests ?? []).map((ask) => (
                            <li key={ask.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <div className="min-w-0">
                                    <span className="block text-sm font-medium text-gray-900">{ask.title}</span>
                                    <span className="text-xs text-gray-400">
                                        {ask.type?.name ?? 'Any file'}
                                        {ask.due_date ? ` · due ${ask.due_date}` : ''}
                                    </span>
                                </div>
                                <span className="flex items-center gap-2">
                                    <RequestPill status={ask.status} />
                                    {ask.status === 'pending' && canSubmit && (
                                        <label className="cursor-pointer text-sm font-medium text-indigo-600 hover:underline">
                                            Upload
                                            <input
                                                type="file"
                                                className="hidden"
                                                disabled={acting}
                                                onChange={(e) => {
                                                    submitFile(ask, e.target.files?.[0] ?? null);
                                                    e.target.value = '';
                                                }}
                                            />
                                        </label>
                                    )}
                                    {ask.status === 'submitted' && canManage && (
                                        <>
                                            <Button size="sm" variant="secondary" disabled={acting} onClick={() => answerAsk(ask, 'accept')}>
                                                Accept
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                disabled={acting}
                                                onClick={() => {
                                                    const reason = window.prompt(`Why is “${ask.title}” being rejected?`, '');

                                                    if (reason !== null) answerAsk(ask, 'reject', reason);
                                                }}
                                            >
                                                Reject
                                            </Button>
                                        </>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="pt-2 text-sm">
                <Link to={hrmsUrl('onboarding')} className="text-indigo-600 hover:text-indigo-800">
                    ← Back to onboarding
                </Link>
            </div>
        </div>
    );
}

function RequestPill({ status }) {
    const color = REQUEST_COLORS[status] ?? '#6b7280';

    return (
        <span
            className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize"
            style={{ backgroundColor: `${color}22`, color }}
        >
            {status}
        </span>
    );
}
