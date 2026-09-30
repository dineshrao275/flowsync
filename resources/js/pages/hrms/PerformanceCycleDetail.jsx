import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
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
    usePageTitle('Review cycle');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
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

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Performance', to: '/hrms/performance' }, { label: 'Cycle' }]);
    }, [setCrumbs]);

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
        ]).catch(fail('Unable to load this cycle.'));
    }, [cycleId, fail]);

    useEffect(() => {
        load();

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cycleId]);

    async function saveGoal(e) {
        e.preventDefault();
        setGoalErrors({});

        try {
            await api.post(`/hrms/performance/cycles/${cycleId}/goals`, goalForm);
            toast.success('Goal filed.');
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
            toast.success('Evidence refreshed.');
            load();
        } catch {
            setError('That goal cannot be refreshed.');
        } finally {
            setRefreshing(null);
        }
    }

    async function saveOneOnOne(e) {
        e.preventDefault();
        setOneOnOneErrors({});

        try {
            await api.post('/hrms/performance/one-on-ones', oneOnOneForm);
            toast.success('One-on-one scheduled.');
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
            toast.success(respondForm.action === 'decline' ? 'Feedback declined.' : 'Feedback submitted.');
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
            toast.success('Review filed as hidden.');
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
            toast.success('Review acknowledged — its owner was notified.');
            load();
        } catch {
            setError('That review cannot be acknowledged.');
        }
    }

    function responsesBlock(request) {
        const responses = request.responses;

        if (!responses) return null;

        if (Array.isArray(responses.rows)) {
            if (responses.rows.length === 0) {
                return <p className="text-xs text-gray-400">{responses.count > 0 ? `Aggregated over ${responses.count} answer${responses.count === 1 ? '' : 's'}${responses.average_rating !== null ? ` · average ${responses.average_rating}` : ''}.` : 'No answers yet.'}</p>;
            }

            return (
                <ul className="mt-1 space-y-1 text-sm">
                    {responses.rows.map((row) => (
                        <li key={row.id} className="rounded bg-gray-50 px-2 py-1">
                            <span className="font-medium">{row.from?.name ?? 'A reviewer'}</span>
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
                <h2 className="text-xl font-semibold text-gray-900">{cycle?.name ?? 'Review cycle'}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {cycle ? `${cycle.stage} · ${cycle.period_start} → ${cycle.period_end}` : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2">
                {TABS.map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setTab(name)}
                        className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === name ? 'bg-indigo-100 text-indigo-800' : 'text-gray-500 hover:bg-gray-100'}`}
                    >
                        {name}
                    </button>
                ))}
            </div>

            {tab === 'goals' && (
                <div className="space-y-3">
                    {canManage && <div><Button size="sm" onClick={() => setGoalModal(true)}>File a goal</Button></div>}
                    {!goals ? <div className="flex justify-center py-8"><Spinner /></div> : goals.map((goal) => (
                        <GoalCard key={goal.id} goal={goal} refreshing={refreshing === goal.id} onRefresh={refreshGoal} />
                    ))}
                </div>
            )}

            {tab === 'check-ins' && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card title="File a check-in" dense>
                        <div className="mb-3 max-w-xs">
                            <Select label="Person" value={checkInFor} onChange={(e) => setCheckInFor(e.target.value)}>
                                <option value="">Select…</option>
                                {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                            </Select>
                        </div>
                        <CheckInComposer cycleId={cycleId} employeeId={checkInFor} onFiled={load} />
                    </Card>
                    <Card title="Notes" dense>
                        {!checkIns ? <div className="flex justify-center py-6"><Spinner /></div> : checkIns.length === 0 ? (
                            <p className="text-sm text-gray-500">No notes yet.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100 text-sm">
                                {checkIns.map((note) => (
                                    <li key={note.id} className="py-2">
                                        <p>{note.body}</p>
                                        <p className="mt-0.5 text-xs text-gray-400">
                                            {note.employee?.name ?? ''}{note.mood ? ` · ${note.mood}` : ''}{note.needs_support ? ' · needs support' : ''}
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
                    <div><Button size="sm" onClick={() => setOneOnOneModal(true)}>Schedule a 1:1</Button></div>
                    {!oneOnOnes ? <div className="flex justify-center py-8"><Spinner /></div> : oneOnOnes.length === 0 ? (
                        <p className="text-sm text-gray-500">No conversations yet.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm">
                            {oneOnOnes.map((row) => (
                                <li key={row.id} className="px-4 py-3">
                                    <p className="font-medium text-gray-900">{row.employee?.name ?? ''} ↔ {row.manager?.name ?? '—'}</p>
                                    <p className="text-xs text-gray-400">{row.scheduled_at ?? 'unscheduled'} · {row.status}</p>
                                    {row.agenda ? <p className="mt-1 text-gray-600">{row.agenda}</p> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {tab === 'feedback' && (
                <div className="space-y-3">
                    {!feedback ? <div className="flex justify-center py-8"><Spinner /></div> : feedback.length === 0 ? (
                        <p className="text-sm text-gray-500">No asks yet — they generate when manager review opens.</p>
                    ) : feedback.map((request) => (
                        <Card key={request.id} dense>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <p>
                                    <span className="font-medium text-gray-900">{request.from?.name ?? '—'}</span>
                                    <span className="text-gray-500"> → {request.to?.name ?? '—'} · {request.relation} · {request.status}</span>
                                </p>
                                {request.status === 'pending' && (
                                    <Button size="sm" variant="secondary" onClick={() => { setResponding(request); setRespondForm({ action: 'submit', rating: '', body: '' }); setRespondErrors({}); }}>
                                        Respond
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
                    {canManage && <div><Button size="sm" onClick={() => setReviewModal(true)}>File a review</Button></div>}
                    {!reviews ? <div className="flex justify-center py-8"><Spinner /></div> : reviews.length === 0 ? (
                        <p className="text-sm text-gray-500">No write-ups yet.</p>
                    ) : reviews.map((review) => (
                        <Card key={review.id} dense>
                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <p className="font-medium text-gray-900">{review.employee?.name ?? ''}</p>
                                <span className="text-xs text-gray-400">{review.status} · {review.visibility_to_employee}</span>
                            </div>
                            <div className="mt-2 grid gap-1 text-sm text-gray-600">
                                {review.self_rating ? <p>Self: {review.self_rating}/5</p> : null}
                                {review.manager_rating ? <p>Manager: {review.manager_rating}/5</p> : null}
                                {review.strengths ? <p><strong>Strengths:</strong> {review.strengths}</p> : null}
                                {review.improvements ? <p><strong>Improvements:</strong> {review.improvements}</p> : null}
                                {review.manager_comments ? <p><strong>Manager:</strong> {review.manager_comments}</p> : null}
                            </div>
                            {canManage && review.status !== 'acknowledged' && (
                                <div className="mt-2"><Button size="sm" variant="secondary" onClick={() => acknowledge(review)}>Acknowledge</Button></div>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            <Modal open={goalModal} onClose={() => setGoalModal(false)} title="File a goal">
                <form onSubmit={saveGoal} className="grid gap-3">
                    <Select label="Person" value={goalForm.employee_id} error={goalErrors.employee_id} onChange={(e) => setGoalForm({ ...goalForm, employee_id: e.target.value })} required>
                        <option value="">Select…</option>
                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                    </Select>
                    <Input label="Title" value={goalForm.title} error={goalErrors.title} onChange={(e) => setGoalForm({ ...goalForm, title: e.target.value })} required />
                    <div className="grid grid-cols-3 gap-3">
                        <Select label="Metric" value={goalForm.metric_type} error={goalErrors.metric_type} onChange={(e) => setGoalForm({ ...goalForm, metric_type: e.target.value })}>
                            <option value="manual">Manual</option>
                            <option value="task_completion">Task completion</option>
                            <option value="worklog_hours">Logged hours</option>
                            <option value="none">None</option>
                        </Select>
                        <Input label="Target" value={goalForm.target_value} error={goalErrors.target_value} onChange={(e) => setGoalForm({ ...goalForm, target_value: e.target.value })} />
                        <Input label="Weight" value={goalForm.weight} error={goalErrors.weight} onChange={(e) => setGoalForm({ ...goalForm, weight: e.target.value })} required />
                    </div>
                    <div><Button type="submit">File goal</Button></div>
                </form>
            </Modal>

            <Modal open={oneOnOneModal} onClose={() => setOneOnOneModal(false)} title="Schedule a 1:1">
                <form onSubmit={saveOneOnOne} className="grid gap-3">
                    <div className="grid grid-cols-2 gap-3">
                        <Select label="Person" value={oneOnOneForm.employee_id} error={oneOnOneErrors.employee_id} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, employee_id: e.target.value })} required>
                            <option value="">Select…</option>
                            {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                        </Select>
                        <Select label="Manager" value={oneOnOneForm.manager_employee_id} error={oneOnOneErrors.manager_employee_id} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, manager_employee_id: e.target.value })}>
                            <option value="">None…</option>
                            {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                        </Select>
                    </div>
                    <Input label="Scheduled at" type="datetime-local" value={oneOnOneForm.scheduled_at} error={oneOnOneErrors.scheduled_at} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, scheduled_at: e.target.value })} required />
                    <Input label="Agenda" value={oneOnOneForm.agenda} error={oneOnOneErrors.agenda} onChange={(e) => setOneOnOneForm({ ...oneOnOneForm, agenda: e.target.value })} />
                    <div><Button type="submit">Schedule</Button></div>
                </form>
            </Modal>

            <Modal open={!!responding} onClose={() => setResponding(null)} title="Answer feedback">
                <form onSubmit={respond} className="grid gap-3">
                    <Select label="Answer" value={respondForm.action} error={respondErrors.action} onChange={(e) => setRespondForm({ ...respondForm, action: e.target.value })}>
                        <option value="submit">Submit perspective</option>
                        <option value="decline">Decline</option>
                    </Select>
                    {respondForm.action === 'submit' && (
                        <>
                            <Input label="Rating (1–5)" value={respondForm.rating} error={respondErrors.rating} onChange={(e) => setRespondForm({ ...respondForm, rating: e.target.value })} required />
                            <Input label="Words" value={respondForm.body} error={respondErrors.body} onChange={(e) => setRespondForm({ ...respondForm, body: e.target.value })} />
                        </>
                    )}
                    <div><Button type="submit">Send answer</Button></div>
                </form>
            </Modal>

            <Modal open={reviewModal} onClose={() => setReviewModal(false)} title="File a review (hidden)">
                <form onSubmit={saveReview} className="grid gap-3">
                    <Select label="Person" value={reviewForm.employee_id} error={reviewErrors.employee_id} onChange={(e) => setReviewForm({ ...reviewForm, employee_id: e.target.value })} required>
                        <option value="">Select…</option>
                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                    </Select>
                    <Input label="Manager rating (1–5)" value={reviewForm.manager_rating} error={reviewErrors.manager_rating} onChange={(e) => setReviewForm({ ...reviewForm, manager_rating: e.target.value })} />
                    <Input label="Strengths" value={reviewForm.strengths} error={reviewErrors.strengths} onChange={(e) => setReviewForm({ ...reviewForm, strengths: e.target.value })} />
                    <Input label="Improvements" value={reviewForm.improvements} error={reviewErrors.improvements} onChange={(e) => setReviewForm({ ...reviewForm, improvements: e.target.value })} />
                    <Input label="Manager comments" value={reviewForm.manager_comments} error={reviewErrors.manager_comments} onChange={(e) => setReviewForm({ ...reviewForm, manager_comments: e.target.value })} />
                    <div><Button type="submit">File hidden</Button></div>
                </form>
            </Modal>
        </div>
    );
}
