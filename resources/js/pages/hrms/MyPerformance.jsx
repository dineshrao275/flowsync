import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { useAuth } from '../../context/AuthContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import GoalCard from '../../components/hrms/GoalCard';
import CheckInComposer from '../../components/hrms/CheckInComposer';

// This page renders per-goal percentages and counts. It must never combine
// goals into a person-score — no sums, no averages across goals, no "overall"
// row. The backend stores no composite score (the schema forbids the column)
// and this page must not invent one on the client.

/**
 * The caller's own performance: goals with evidence, check-in history, 1:1s
 * and feedback both ways.
 *
 * Self-scoped on purpose — this page rides the module alone like My files.
 * The record is matched by login against the directory; drafts file through
 * the same goal endpoint managers use, because a goal is a goal whoever
 * files it.
 */
export default function MyPerformance() {
    usePageTitle('My performance');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const { user } = useAuth();

    const [cycles, setCycles] = useState(null);
    const [cycleId, setCycleId] = useState('');
    const [goals, setGoals] = useState(null);
    const [checkIns, setCheckIns] = useState([]);
    const [oneOnOnes, setOneOnOnes] = useState([]);
    const [feedback, setFeedback] = useState([]);
    const [employeeId, setEmployeeId] = useState(null);
    const [error, setError] = useState(null);

    const [modal, setModal] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My performance' }]);
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

    const loadCycles = useCallback(() => {
        return api
            .get('/hrms/performance/cycles')
            .then(({ data }) => {
                const rows = data.cycles ?? [];
                setCycles(rows);
                setCycleId((current) => current || (rows[0] ? String(rows[0].id) : ''));
            })
            .catch(fail('Unable to load cycles.'));
    }, [fail]);

    const loadCycle = useCallback(
        (id) => {
            if (!id) {
                setGoals(null);
                return Promise.resolve();
            }

            // Unresolved record means unresolved scope: show nothing rather
            // than everything while the directory is still loading.
            if (employeeId === null) {
                setGoals([]);
                setCheckIns([]);
                setFeedback([]);
                return Promise.resolve();
            }

            return Promise.all([
                api.get(`/hrms/performance/cycles/${id}/goals`).then(({ data }) => setGoals(
                    (data.goals ?? []).filter((g) => g.employee_id === employeeId),
                )),
                api.get(`/hrms/performance/cycles/${id}/check-ins`).then(({ data }) => setCheckIns(
                    (data.check_ins ?? []).filter((c) => c.employee_id === employeeId),
                )),
                api.get(`/hrms/performance/cycles/${id}/feedback-requests`).then(({ data }) => setFeedback(data.feedback_requests ?? [])),
            ]).catch(fail('Unable to load this cycle.'));
        },
        [employeeId, fail],
    );

    useEffect(() => {
        loadCycle(cycleId);
    }, [cycleId, loadCycle]);

    useEffect(() => {
        loadCycles();

        api.get('/hrms/performance/one-on-ones').then(({ data }) => setOneOnOnes(data.one_on_ones ?? [])).catch(() => setOneOnOnes([]));

        // The record this login drafts under: the directory row whose user
        // block names the login. Resolved once here and passed down, so no
        // form ever asks the person to type their own id.
        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployeeId((data.employees ?? []).find((e) => e.user?.id === user?.id)?.id ?? null))
            .catch(() => setEmployeeId(null));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const myOneOnOnes = oneOnOnes.filter(
        (row) => employeeId !== null && (row.employee_id === employeeId || row.manager_employee_id === employeeId),
    );

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">My performance</h2>
                    <p className="mt-0.5 text-sm text-gray-500">Goals, notes, conversations, and feedback.</p>
                </div>
                {cycleId && <Button onClick={() => setModal(true)}>Draft a goal</Button>}
            </div>

            {error && <Alert>{error}</Alert>}

            {!cycles ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : cycles.length === 0 ? (
                <EmptyState title="No cycles yet" hint="Your goals appear here once a cycle opens." />
            ) : (
                <>
                    <div className="flex flex-wrap gap-2">
                        {cycles.map((cycle) => (
                            <button
                                key={cycle.id}
                                type="button"
                                onClick={() => setCycleId(String(cycle.id))}
                                className={`rounded-lg px-3 py-1.5 text-sm font-medium ${String(cycle.id) === cycleId ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`}
                            >
                                {cycle.name}
                            </button>
                        ))}
                    </div>

                    {!goals ? (
                        <div className="flex justify-center py-8"><Spinner /></div>
                    ) : (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card title="My goals" dense>
                                {goals.length === 0 ? (
                                    <p className="text-sm text-gray-500">No goals in this cycle yet.</p>
                                ) : (
                                    <div className="space-y-3">
                                        {goals.map((goal) => <GoalCard key={goal.id} goal={goal} />)}
                                    </div>
                                )}
                            </Card>
                            <div className="space-y-4">
                                <Card title="Check-ins" dense>
                                    {checkIns.length === 0 ? (
                                        <p className="text-sm text-gray-500">No notes in this cycle yet.</p>
                                    ) : (
                                        <ul className="divide-y divide-gray-100 text-sm">
                                            {checkIns.map((note) => (
                                                <li key={note.id} className="py-2">
                                                    <p>{note.body}</p>
                                                    {note.mood ? <p className="text-xs text-gray-400">{note.mood}</p> : null}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                                <Card title="Feedback" dense>
                                    {feedback.length === 0 ? (
                                        <p className="text-sm text-gray-500">No asks in this cycle yet.</p>
                                    ) : (
                                        <ul className="divide-y divide-gray-100 text-sm">
                                            {feedback.map((request) => (
                                                <li key={request.id} className="py-2">
                                                    <p>
                                                        <span className="font-medium">{request.from?.name ?? '—'}</span>
                                                        <span className="text-gray-500"> → {request.to?.name ?? '—'} · {request.status}</span>
                                                    </p>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                                <Card title="One-on-ones" dense>
                                    {myOneOnOnes.length === 0 ? (
                                        <p className="text-sm text-gray-500">No conversations yet.</p>
                                    ) : (
                                        <ul className="divide-y divide-gray-100 text-sm">
                                            {myOneOnOnes.map((row) => (
                                                <li key={row.id} className="py-2">
                                                    <p>{row.employee?.name ?? ''} ↔ {row.manager?.name ?? '—'}</p>
                                                    <p className="text-xs text-gray-400">{row.scheduled_at ?? 'unscheduled'} · {row.status}</p>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                            </div>
                        </div>
                    )}
                </>
            )}

            <Modal open={modal} onClose={() => setModal(false)} title="Draft a goal">
                {employeeId ? (
                    <GoalDraftForm
                        cycleId={cycleId}
                        employeeId={employeeId}
                        onDrafted={() => {
                            setModal(false);
                            toast.success('Goal drafted.');
                            loadCycle(cycleId);
                        }}
                    />
                ) : (
                    <p className="text-sm text-gray-500">No employment record found for this login.</p>
                )}
            </Modal>
        </div>
    );
}

function GoalDraftForm({ cycleId, employeeId, onDrafted }) {
    const [form, setForm] = useState({ title: '', metric_type: 'manual', target_value: '', weight: '100' });
    const [errors, setErrors] = useState({});

    async function save(e) {
        e.preventDefault();
        setErrors({});

        try {
            await api.post(`/hrms/performance/cycles/${cycleId}/goals`, {
                ...form,
                employee_id: Number(employeeId),
            });
            onDrafted();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    return (
        <form onSubmit={save} className="grid gap-3">
            <Input label="Title" value={form.title} error={errors.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required />
            <div className="grid grid-cols-2 gap-3">
                <Input label="Target (optional)" value={form.target_value} error={errors.target_value} onChange={(e) => setForm({ ...form, target_value: e.target.value })} />
                <Input label="Weight" value={form.weight} error={errors.weight} onChange={(e) => setForm({ ...form, weight: e.target.value })} required />
            </div>
            <div><Button type="submit">Draft goal</Button></div>
        </form>
    );
}
