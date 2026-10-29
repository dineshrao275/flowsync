import { useCallback, useEffect, useState } from 'react';
import PersonSelect from '../../components/ui/PersonSelect';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import GoalCard from '../../components/hrms/GoalCard';
import CheckInComposer from '../../components/hrms/CheckInComposer';

const TABS = ['goals', 'check-ins', 'one-on-ones', 'feedback', 'reviews'];

/**
 * One cycle's five rooms: goals with evidence, check-in notes, 1:1s,
 * feedback asks with their anonymity tiers, and review write-ups.
 *
 * Goal creation files under the picked person (managers and HR); the
 * evidence button re-photographs on demand; reviews file through the
 * manager with visibility defaulting to hidden. Nothing here computes a
 * score — see GoalCard's contract — and the acknowledge button seals a
 * write-up while notifying its owner.
 */
export default function PerformanceCycleDetail() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.cycleDetail.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();
    const { can } = useAuth();
    const { cycleId } = useParams();

    const canManage = can('hrms.performance.manage');

    const [tab, setTab] = useState('goals');
    const [cycle, setCycle] = useState(null);
    const [goals, setGoals] = useState(null);
    const [checkIns, setCheckIns] = useState(null);
    const [oneOnOnes, setOneOnOnes] = useState(null);
    const [feedback, setFeedback] = useState(null);
    const [reviews, setReviews] = useState(null);
    const [employees, setEmployees] = useState([]);
    const [error, setError] = useState(null);
    const [refreshing, setRefreshing] = useState(null);

    const [goalModal, setGoalModal] = useState(false);
    const [goalForm, setGoalForm] = useState({ employee_id: '', title: '', metric_type: 'manual', target_value: '', weight: '100', status: 'draft' });
    const [goalErrors, setGoalErrors] = useState({});

    const [checkInFor, setCheckInFor] = useState('');
    const [oneOnOneModal, setOneOnOneModal] = useState(false);
    const [oneOnOneForm, setOneOnOneForm] = useState({ employee_id: '', manager_employee_id: '', scheduled_at: '', agenda: '' });
    const [oneOnOneErrors, setOneOnOneErrors] = useState({});

    const [reviewModal, setReviewModal] = useState(false);
    const [reviewForm, setReviewForm] = useState({ employee_id: '', manager_rating: '', strengths: '', improvements: '', manager_comments: '', visibility_to_employee: 'hidden' });
    const [reviewErrors, setReviewErrors] = useState({});

    const [responding, setResponding] = useState(null);
    const [respondForm, setRespondForm] = useState({ action: 'submit', rating: '', body: '' });
    const [respondErrors, setRespondErrors] = useState({});

    // The caller's OWN employee row, resolved from `/my/hr` — the one
    // self-scoped HRMS endpoint, cached server side and readable without a
    // directory grant. It backs two gates that mirror the policies exactly:
    // CheckInPolicy::create files for self (or `hrms.performance.manage`) and
    // OneOnOnePolicy::create needs any employee record (or manage). Deriving
    // it from the `/hrms/employees` page instead would make the gates answer
    // "which 100 people did this page happen to load" rather than "does the
    // caller have a record" — the select is paged, and a reader the directory
    // denies would be told they have no record at all.
    const [selfEmployeeId, setSelfEmployeeId] = useState(null);

    useEffect(() => {
        api.get('/my/hr')
            .then(({ data }) => setSelfEmployeeId(data.profile?.id ?? null))
            .catch(() => setSelfEmployeeId(null));
    }, []);

    useEffect(() => {
        setCrumbs([
            { label: t('hrms.overview.pageTitle'), to: '/hrms' },
            { label: t('hrms.performance.pageTitle'), to: '/hrms/performance' },
            { label: t('hrms.cycleDetail.crumbCycle') },
        ]);
    }, [setCrumbs, t]);

    const fail = useCallback(
        (message) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError(message);
        },
        [navigate],
    );

    const load = useCallback(() => {
        setError(null);

        return Promise.all([
            api.get(`/hrms/performance/cycles/${cycleId}`).then(({ data }) => setCycle(data.cycle)),
            api.get(`/hrms/performance/cycles/${cycleId}/goals`).then(({ data }) => setGoals(data.goals ?? [])),
            api.get(`/hrms/performance/cycles/${cycleId}/check-ins`).then(({ data }) => setCheckIns(data.check_ins ?? [])),
            api.get('/hrms/performance/one-on-ones').then(({ data }) => setOneOnOnes(data.one_on_ones ?? [])),
            api.get(`/hrms/performance/cycles/${cycleId}/feedback-requests`).then(({ data }) => setFeedback(data.feedback_requests ?? [])),
            api.get(`/hrms/performance/cycles/${cycleId}/reviews`).then(({ data }) => setReviews(data.reviews ?? [])),
        ]).catch(fail(t('hrms.cycleDetail.loadFailed')));
    }, [cycleId, fail, t]);

    useEffect(() => {
        load();

        api.get('/hrms/employees', { params: { per_page: 250 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name, email: e.user?.email }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cycleId]);

    async function saveGoal(e) {
        e.preventDefault();
        setGoalErrors({});

        try {
            await api.post(`/hrms/performance/cycles/${cycleId}/goals`, goalForm);
            toast.success(t('hrms.cycleDetail.goalFiled'));
            setGoalModal(false);
            setGoalForm({ employee_id: '', title: '', metric_type: 'manual', target_value: '', weight: '100', status: 'draft' });
            load();
        } catch (err) {
            setGoalErrors(fieldErrors(err));
        }
    }

    async function refreshGoal(goal) {
        setRefreshing(goal.id);

        try {
            await api.post(`/hrms/performance/goals/${goal.id}/refresh`, {});
            toast.success(t('hrms.cycleDetail.evidenceRefreshed'));
            load();
        } catch {
            setError(t('hrms.cycleDetail.goalRefreshFailed'));
        } finally {
            setRefreshing(null);
        }
    }

    async function linkGoalTask(goal, taskKey) {
        try {
            await api.post(`/hrms/performance/goals/${goal.id}/tasks`, { task_key: taskKey });
            toast.success(t('hrms.cycleDetail.taskLinked'));
            load();
        } catch {
            setError(t('hrms.cycleDetail.taskLinkFailed'));
        }
    }

    async function unlinkGoalTask(goal, task) {
        if (!(await confirm({ title: t('hrms.cycleDetail.unlinkTitle'), message: t('hrms.cycleDetail.unlinkMessage', { key: task.key }), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/performance/goals/${goal.id}/tasks/${task.id}`);
            toast.success(t('hrms.cycleDetail.taskUnlinked'));
            load();
        } catch {
            setError(t('hrms.cycleDetail.taskUnlinkFailed'));
        }
    }

    async function saveOneOnOne(e) {
        e.preventDefault();
        setOneOnOneErrors({});

        try {
            await api.post('/hrms/performance/one-on-ones', oneOnOneForm);
            toast.success(t('hrms.cycleDetail.oneOnOneScheduled'));
            setOneOnOneModal(false);
            setOneOnOneForm({ employee_id: '', manager_employee_id: '', scheduled_at: '', agenda: '' });
            load();
        } catch (err) {
            setOneOnOneErrors(fieldErrors(err));
        }
    }

    async function respond(e) {
        e.preventDefault();
        setRespondErrors({});

        try {
            await api.post(`/hrms/performance/feedback-requests/${responding.id}/respond`, respondForm);
            toast.success(respondForm.action === 'decline' ? t('hrms.cycleDetail.feedbackDeclined') : t('hrms.cycleDetail.feedbackSubmitted'));
            setResponding(null);
            load();
        } catch (err) {
            setRespondErrors(fieldErrors(err));
        }
    }

    async function saveReview(e) {
        e.preventDefault();
        setReviewErrors({});

        try {
            await api.post(`/hrms/performance/cycles/${cycleId}/reviews`, reviewForm);
            toast.success(t('hrms.cycleDetail.reviewFiled'));
            setReviewModal(false);
            setReviewForm({ employee_id: '', manager_rating: '', strengths: '', improvements: '', manager_comments: '', visibility_to_employee: 'hidden' });
            load();
        } catch (err) {
            setReviewErrors(fieldErrors(err));
        }
    }

    async function acknowledge(review) {
        try {
            await api.post(`/hrms/performance/reviews/${review.id}/acknowledge`, {});
            toast.success(t('hrms.cycleDetail.reviewAcknowledged'));
            load();
        } catch {
            setError(t('hrms.cycleDetail.reviewAcknowledgeFailed'));
        }
    }

    function responsesBlock(request) {
        const responses = request.responses;

        if (!responses) return null;

        if (Array.isArray(responses.rows)) {
            if (responses.rows.length === 0) {
                const avgSuffix = responses.average_rating !== null
                    ? t('hrms.cycleDetail.feedbackAvgSuffix', { avg: responses.average_rating })
                    : '';

                return (
                    <p className="text-xs text-gray-400">
                        {responses.count > 0
                            ? t(responses.count === 1 ? 'hrms.cycleDetail.feedbackAggregatedOne' : 'hrms.cycleDetail.feedbackAggregated', { count: responses.count, avgSuffix })
                            : t('hrms.cycleDetail.noAnswersYet')}
                    </p>
                );
            }

            return (
                <ul className="mt-1 space-y-1 text-sm">
                    {responses.rows.map((row) => (
                        <li key={row.id} className="rounded bg-gray-50 px-2 py-1">
                            <span className="font-medium">{row.from?.name ?? t('hrms.cycleDetail.anonymousReviewer')}</span>
                            {row.rating ? ` · ${row.rating}/5` : ''}
                            {row.body ? <span className="block text-gray-600">{row.body}</span> : null}
                        </li>
                    ))}
                </ul>
            );
        }

        return null;
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{cycle?.name ?? t('hrms.cycleDetail.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {cycle ? t('hrms.cycleDetail.headerMeta', { stage: cycle.stage, start: cycle.period_start, end: cycle.period_end }) : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2">
                {TABS.map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setTab(name)}
                        className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === name ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]' : 'text-gray-500 hover:bg-gray-100'}`}
                    >
                        {t(`hrms.cycleDetail.tabs.${name}`)}
                    </button>
                ))}
            </div>

            {tab === 'goals' && (
                <div className="space-y-3">
                    {canManage && <div><Button size="sm" onClick={() => setGoalModal(true)}>{t('hrms.cycleDetail.fileGoal')}</Button></div>}
                    {!goals ? <ContentSkeleton size="md" /> : goals.length === 0 ? (
                        <p className="py-8 text-center text-sm text-gray-400">{t('hrms.cycleDetail.noGoals')}</p>
                    ) : goals.map((goal) => (
                        <GoalCard
                            key={goal.id}
                            goal={goal}
                            refreshing={refreshing === goal.id}
                            onRefresh={refreshGoal}
                            onLink={canManage ? linkGoalTask : null}
                            onUnlink={canManage ? unlinkGoalTask : null}
                        />
                    ))}
                </div>
            )}

            {tab === 'check-ins' && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card title={t('hrms.cycleDetail.fileCheckIn')} dense>
                        {canManage ? (
                            <>
                                <div className="mb-3 max-w-xs">
                                    <PersonSelect label={t('hrms.cycleDetail.person')} value={checkInFor} onChange={(e) => setCheckInFor(e.target.value)} placeholder={t('hrms.cycleDetail.select')} people={employees} />
                                </div>
                                <CheckInComposer cycleId={cycleId} employeeId={checkInFor} onFiled={load} />
                            </>
                        ) : selfEmployeeId !== null ? (
                            <>
                                <p className="mb-3 text-xs text-gray-400">{t('hrms.cycleDetail.selfNote')}</p>
                                <CheckInComposer cycleId={cycleId} employeeId={selfEmployeeId} onFiled={load} />
                            </>
                        ) : (
                            <p className="text-sm text-gray-500">
                                {t('hrms.cycleDetail.noEmployeeRecord')}
                            </p>
                        )}
                    </Card>
                    <Card title={t('hrms.cycleDetail.notes')} dense>
                        {!checkIns ? <ContentSkeleton size="sm" /> : checkIns.length === 0 ? (
                            <p className="text-sm text-gray-500">{t('hrms.cycleDetail.noNotes')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-100 text-sm">
                                {checkIns.map((note) => (
                                    <li key={note.id} className="py-2">
                                        <p>{note.body}</p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {note.employee?.name ?? ''}{note.mood ? ` · ${note.mood}` : ''}{note.needs_support ? t('hrms.cycleDetail.needsSupportSuffix') : ''}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            )}

            {tab === 'one-on-ones' && (
                <div className="space-y-3">
                    {(canManage || selfEmployeeId !== null) && (
                        <div><Button size="sm" onClick={() => setOneOnOneModal(true)}>{t('hrms.cycleDetail.scheduleOneOnOne')}</Button></div>
                    )}
                    {!oneOnOnes ? <ContentSkeleton size="md" /> : oneOnOnes.length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.cycleDetail.noConversations')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm">
                            {oneOnOnes.map((row) => (
                                <li key={row.id} className="px-4 py-3">
                                    <p className="font-medium text-gray-900">{row.employee?.name ?? ''} ↔ {row.manager?.name ?? '—'}</p>
                                    <p className="text-xs text-gray-400">{row.scheduled_at ?? t('hrms.cycleDetail.unscheduled')} · {row.status}</p>
                                    {row.agenda ? <p className="mt-1 text-gray-600">{row.agenda}</p> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {tab === 'feedback' && (
                <div className="space-y-3">
                    {!feedback ? <ContentSkeleton size="md" /> : feedback.length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.cycleDetail.noFeedbackAsks')}</p>
                    ) : feedback.map((request) => (
                        <Card key={request.id} dense>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <p>
                                    <span className="font-medium text-gray-900">{request.from?.name ?? '—'}</span>
                                    <span className="text-gray-500"> → {request.to?.name ?? '—'} · {request.relation} · {request.status}</span>
                                </p>
                                {request.status === 'pending' && (
                                    <Button size="sm" variant="secondary" onClick={() => { setResponding(request); setRespondForm({ action: 'submit', rating: '', body: '' }); setRespondErrors({}); }}>
                                        {t('hrms.cycleDetail.respond')}
                                    </Button>
                                )}
                            </div>
                            {responsesBlock(request)}
                        </Card>
                    ))}
                </div>
            )}

            {tab === 'reviews' && (
                <div className="space-y-3">
                    {canManage && <div><Button size="sm" onClick={() => setReviewModal(true)}>{t('hrms.cycleDetail.fileReview')}</Button></div>}
                    {!reviews ? <ContentSkeleton size="md" /> : reviews.length === 0 ? (
                        <p className="text-sm text-gray-500">{t('hrms.cycleDetail.noWriteUps')}</p>
                    ) : reviews.map((review) => (
                        <Card key={review.id} dense>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <p className="font-medium text-gray-900">{review.employee?.name ?? ''}</p>
                                <span className="text-xs text-gray-400">{review.status} · {review.visibility_to_employee}</span>
                            </div>
                            <div className="mt-2 grid gap-1 text-sm text-gray-600">
                                {review.self_rating ? <p>{t('hrms.cycleDetail.selfRating', { rating: review.self_rating })}</p> : null}
                                {review.manager_rating ? <p>{t('hrms.cycleDetail.managerRatingLabelValue', { rating: review.manager_rating })}</p> : null}
                                {review.strengths ? <p><strong>{t('hrms.cycleDetail.strengths')}:</strong> {review.strengths}</p> : null}
                                {review.improvements ? <p><strong>{t('hrms.cycleDetail.improvements')}:</strong> {review.improvements}</p> : null}
                                {review.manager_comments ? <p><strong>{t('hrms.cycleDetail.managerCommentsBold')}</strong> {review.manager_comments}</p> : null}
                            </div>
                            {canManage && review.status !== 'acknowledged' && (
                                <div className="mt-2"><Button size="sm" variant="secondary" onClick={() => acknowledge(review)}>{t('hrms.cycleDetail.acknowledge')}</Button></div>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <Modal open={goalModal} onClose={() => setGoalModal(false)} title={t('hrms.cycleDetail.fileGoal')}>
                <form onSubmit={saveGoal} className="grid gap-3 px-6 py-5">
                    <PersonSelect label={t('hrms.cycleDetail.person')} value={goalForm.employee_id} error={goalErrors.employee_id} onChange={(e) => setGoalForm({ ...goalForm, employee_id: e.target.value })} required placeholder={t('hrms.cycleDetail.select')} people={employees} />
                    <Input label={t('hrms.cycleDetail.titleLabel')} value={goalForm.title} error={goalErrors.title} onChange={(e) => setGoalForm({ ...goalForm, title: e.target.value })} required />
                    <div className="grid grid-cols-3 gap-3">
                        <Select label={t('hrms.cycleDetail.metric')} value={goalForm.metric_type} error={goalErrors.metric_type} onChange={(e) => setGoalForm({ ...goalForm, metric_type: e.target.value })}>
                            <option value="manual">{t('hrms.cycleDetail.metricManual')}</option>
                            <option value="task_completion">{t('hrms.cycleDetail.metricTaskCompletion')}</option>
                            <option value="worklog_hours">{t('hrms.cycleDetail.metricWorklogHours')}</option>
                            <option value="none">{t('hrms.cycleDetail.metricNone')}</option>
                        </Select>
                        <Input label={t('hrms.cycleDetail.target')} value={goalForm.target_value} error={goalErrors.target_value} onChange={(e) => setGoalForm({ ...goalForm, target_value: e.target.value })} />
                        <Input label={t('hrms.cycleDetail.weight')} value={goalForm.weight} error={goalErrors.weight} onChange={(e) => setGoalForm({ ...goalForm, weight: e.target.value })} required />
                    </div>
                    <div><Button type="submit">{t('hrms.cycleDetail.fileGoalBtn')}</Button></div>
                </form>
            </Modal>

            <Modal open={oneOnOneModal} onClose={() => setOneOnOneModal(false)} title={t('hrms.cycleDetail.scheduleOneOnOneTitle')}>
                <form onSubmit={saveOneOnOne} className="grid gap-3 px-6 py-5">
                    <div className="grid grid-cols-2 gap-3">
                        <PersonSelect label={t('hrms.cycleDetail.person')} value={oneOnOneForm.employee_id} error={oneOnOneErrors.employee_id} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, employee_id: e.target.value })} required placeholder={t('hrms.cycleDetail.select')} people={employees} />
                        <PersonSelect label={t('hrms.cycleDetail.manager')} value={oneOnOneForm.manager_employee_id} error={oneOnOneErrors.manager_employee_id} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, manager_employee_id: e.target.value })} placeholder={t('hrms.cycleDetail.managerOptionNone')} people={employees} />
                    </div>
                    <Input label={t('hrms.cycleDetail.scheduledAt')} type="datetime-local" value={oneOnOneForm.scheduled_at} error={oneOnOneErrors.scheduled_at} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, scheduled_at: e.target.value })} required />
                    <Input label={t('hrms.cycleDetail.agenda')} value={oneOnOneForm.agenda} error={oneOnOneErrors.agenda} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, agenda: e.target.value })} />
                    <div><Button type="submit">{t('hrms.cycleDetail.schedule')}</Button></div>
                </form>
            </Modal>

            <Modal open={!!responding} onClose={() => setResponding(null)} title={t('hrms.cycleDetail.answerFeedbackTitle')}>
                <form onSubmit={respond} className="grid gap-3 px-6 py-5">
                    <Select label={t('hrms.cycleDetail.answer')} value={respondForm.action} error={respondErrors.action} onChange={(e) => setRespondForm({ ...respondForm, action: e.target.value })}>
                        <option value="submit">{t('hrms.cycleDetail.answerSubmit')}</option>
                        <option value="decline">{t('hrms.cycleDetail.answerDecline')}</option>
                    </Select>
                    {respondForm.action === 'submit' && (
                        <>
                            <Input label={t('hrms.cycleDetail.ratingLabel')} value={respondForm.rating} error={respondErrors.rating} onChange={(e) => setRespondForm({ ...respondForm, rating: e.target.value })} required />
                            <Input label={t('hrms.cycleDetail.words')} value={respondForm.body} error={respondErrors.body} onChange={(e) => setRespondForm({ ...respondForm, body: e.target.value })} />
                        </>
                    )}
                    <div><Button type="submit">{t('hrms.cycleDetail.sendAnswer')}</Button></div>
                </form>
            </Modal>

            <Modal open={reviewModal} onClose={() => setReviewModal(false)} title={t('hrms.cycleDetail.reviewModalTitle')}>
                <form onSubmit={saveReview} className="grid gap-3 px-6 py-5">
                    <PersonSelect label={t('hrms.cycleDetail.person')} value={reviewForm.employee_id} error={reviewErrors.employee_id} onChange={(e) => setReviewForm({ ...reviewForm, employee_id: e.target.value })} required placeholder={t('hrms.cycleDetail.select')} people={employees} />
                    <Input label={t('hrms.cycleDetail.managerRatingLabel')} value={reviewForm.manager_rating} error={reviewErrors.manager_rating} onChange={(e) => setReviewForm({ ...reviewForm, manager_rating: e.target.value })} />
                    <Input label={t('hrms.cycleDetail.strengths')} value={reviewForm.strengths} error={reviewErrors.strengths} onChange={(e) => setReviewForm({ ...reviewForm, strengths: e.target.value })} />
                    <Input label={t('hrms.cycleDetail.improvements')} value={reviewForm.improvements} error={reviewErrors.improvements} onChange={(e) => setReviewForm({ ...reviewForm, improvements: e.target.value })} />
                    <Input label={t('hrms.cycleDetail.managerComments')} value={reviewForm.manager_comments} error={reviewErrors.manager_comments} onChange={(e) => setReviewForm({ ...reviewForm, manager_comments: e.target.value })} />
                    <div><Button type="submit">{t('hrms.cycleDetail.fileHidden')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
