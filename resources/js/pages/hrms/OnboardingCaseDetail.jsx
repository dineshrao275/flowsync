import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import StatusPill from '../../components/ui/StatusPill';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm, usePrompt } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { hrmsUrl } from '../../utils/deepLinks';
import Checklist from '../../components/hrms/Checklist';
import ConvertTaskModal from '../../components/hrms/ConvertTaskModal';

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
    const { t } = useTranslation();
    const { caseId } = useParams();
    const navigate = useNavigate();
    const setCrumbs = useSetCrumbs();
    const { user, can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const prompt = usePrompt();

    const [detail, setDetail] = useState(null);
    const [error, setError] = useState(null);
    const [acting, setActing] = useState(false);
    const [converting, setConverting] = useState(null);

    const canManage = can('permission:hrms.onboarding.manage');

    usePageTitle(detail ? t('hrms.onboarding.detailPerson', { name: detail.employee?.name ?? '' }) : t('hrms.onboarding.detailTitle'));

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

                setError(t('hrms.onboarding.loadDetailFailed'));
            });
    }, [caseId, navigate, t]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!detail) return;

        setCrumbs([
            { label: t('hrms.overview.pageTitle'), to: '/hrms' },
            { label: t('hrms.onboarding.pageTitle'), to: hrmsUrl('onboarding') },
            { label: detail.employee?.name ?? t('hrms.onboarding.crumbCase', { id: detail.id }) },
        ]);
    }, [detail, setCrumbs, t]);

    async function completeTask(task) {
        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/tasks/${task.id}/complete`, {});
            await load();
        } catch {
            toast.error(t('hrms.caseDetail.completeItemFailed'));
        } finally {
            setActing(false);
        }
    }

    async function waiveTask(task, reason) {
        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/tasks/${task.id}/waive`, { reason });
            toast.success(t('hrms.onboarding.itemWaived'));
            await load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? t('hrms.onboarding.waiveFailed'));
        } finally {
            setActing(false);
        }
    }

    async function syncItem(task) {
        setActing(true);

        try {
            const { data } = await api.post(`/hrms/onboarding/cases/${caseId}/tasks/${task.id}/sync`, {});
            toast.success(data.message ?? t('hrms.caseDetail.synced'));
            await load();
        } catch (err) {
            toast.error(err.response?.data?.message ?? t('hrms.caseDetail.syncFailed'));
        } finally {
            setActing(false);
        }
    }

    async function complete() {
        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/complete`, {});
            toast.success(t('hrms.onboarding.completed'));
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).form ?? t('hrms.onboarding.completeFailed'));
        } finally {
            setActing(false);
        }
    }

    async function cancel() {
        if (!(await confirm({ title: t('hrms.onboarding.cancelTitle'), message: t('hrms.onboarding.cancelMessage'), variant: 'danger' }))) return;

        setActing(true);

        try {
            await api.post(`/hrms/onboarding/cases/${caseId}/cancel`, {});
            toast.success(t('hrms.onboarding.cancelDone'));
            await load();
        } catch {
            toast.error(t('hrms.onboarding.cancelFailed'));
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
            toast.success(t('hrms.onboarding.fileSubmitted'));
            await load();
        } catch (err) {
            toast.error(fieldErrors(err).file ?? fieldErrors(err).form ?? t('hrms.onboarding.fileFailed'));
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
            toast.error(fieldErrors(err).form ?? fieldErrors(err).reason ?? t('hrms.onboarding.answerFailed'));
        } finally {
            setActing(false);
        }
    }

    if (error || (detail === null && error !== null)) {
        return <Alert>{error ?? t('hrms.onboarding.notFound')}</Alert>;
    }

    if (!detail) {
        return (
            <ContentSkeleton size="lg" />
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
                        {detail.template?.name ?? t('hrms.onboarding.noTemplate')} · {detail.status_label ?? detail.status} ·{' '}
                        {t('hrms.onboarding.pctComplete', { percent: progress.percent ?? 0 })}
                        {progress.mandatory_open > 0 && ` · ${t('hrms.onboarding.mandatoryOpen', { count: progress.mandatory_open })}`}
                    </p>
                </div>

                {canManage && open && (
                    <div className="flex gap-2">
                        <Button variant="secondary" loading={acting} onClick={complete}>
                            {t('hrms.onboarding.completeCase')}
                        </Button>
                        <Button variant="ghost" loading={acting} onClick={cancel}>
                            {t('hrms.common.cancel')}
                        </Button>
                    </div>
                )}
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-gray-100">
                <div className="h-full rounded-full bg-[var(--accent-soft)]0" style={{ width: `${progress.percent ?? 0}%` }} />
            </div>

            <Card title={t('hrms.caseDetail.checklist')} dense>
                <Checklist
                    tasks={detail.tasks ?? []}
                    canAct={open && canWork}
                    canWaive={canManage || isSelf}
                    onComplete={completeTask}
                    onWaive={waiveTask}
                    canConvert={open && canWork}
                    onConvert={setConverting}
                    onSync={syncItem}
                />
            </Card>

            {converting && (
                <ConvertTaskModal
                    caseKind="onboarding"
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
                <Card title={t('hrms.caseDetail.docAsks')} dense>
                    <ul className="divide-y divide-gray-100">
                        {(detail.requests ?? []).map((ask) => (
                            <li key={ask.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <div className="min-w-0">
                                    <span className="block text-sm font-medium text-gray-900">{ask.title}</span>
                                    <span className="text-xs text-gray-400">
                                        {ask.type?.name ?? t('hrms.caseDetail.anyFile')}
                                        {ask.due_date ? t('hrms.caseDetail.due', { date: ask.due_date }) : ''}
                                    </span>
                                </div>
                                <span className="flex items-center gap-2">
                                    <RequestPill status={ask.status} />
                                    {ask.status === 'pending' && canSubmit && (
                                        <label className="cursor-pointer text-sm font-medium text-[var(--accent-soft-text)] hover:underline">
                                            {t('hrms.onboarding.upload')}
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
                                                {t('hrms.onboarding.accept')}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                disabled={acting}
                                                onClick={async () => {
                                                    const reason = await prompt({
                                                        title: t('hrms.onboarding.rejectTitle'),
                                                        message: t('hrms.onboarding.rejectMessage', { title: ask.title }),
                                                        placeholder: t('hrms.onboarding.rejectPlaceholder'),
                                                        required: true,
                                                        confirmLabel: t('hrms.onboarding.reject'),
                                                        variant: 'danger',
                                                    });

                                                    if (reason !== null) answerAsk(ask, 'reject', reason);
                                                }}
                                            >
                                                {t('hrms.onboarding.reject')}
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
                <Link to={hrmsUrl('onboarding')} className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">
                    {t('hrms.onboarding.backLink')}
                </Link>
            </div>
        </div>
    );
}

function RequestPill({ status }) {
    return <StatusPill color={REQUEST_COLORS[status]} label={status} />;
}
