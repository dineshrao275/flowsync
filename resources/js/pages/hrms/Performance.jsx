import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
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

const STAGES = ['goal_setting', 'check_in', 'self_review', 'manager_review', 'calibration', 'completed'];
const STAGE_LABELS = {
    goal_setting: 'Goal setting',
    check_in: 'Check-in',
    self_review: 'Self review',
    manager_review: 'Manager review',
    calibration: 'Calibration',
    completed: 'Completed',
};
const NEXT_STEP = {
    goal_setting: { action: 'open-check-in', label: 'Open check-ins', done: 'Check-ins opened.' },
    check_in: { action: 'open-self-review', label: 'Open self review', done: 'Self review opened.' },
    self_review: { action: 'open-manager-review', label: 'Open manager review', done: 'Manager review opened.' },
    manager_review: { action: 'open-calibration', label: 'Open calibration', done: 'Calibration opened.' },
    calibration: { action: 'complete', label: 'Complete cycle', done: 'Cycle completed.' },
};

const emptyCycle = { name: '', description: '', period_start: '', period_end: '', anonymity: 'none' };

/**
 * Review cycles: the list with a stage stepper, the creation wizard, and
 * the forward-only transitions.
 *
 * A manager screen end to end — reads ride the view permission, every move
 * needs manage (the backend 403s regardless). The stepper is display, not
 * navigation: stages move forward through the buttons, never by clicking
 * a step, because the service refuses out-of-order moves and the UI must
 * not offer what the backend will not do.
 */
export default function Performance() {
    usePageTitle('Performance');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('hrms.performance.manage');

    const [cycles, setCycles] = useState(null);
    const [error, setError] = useState(null);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState(emptyCycle);
    const [formErrors, setFormErrors] = useState({});
    const [acting, setActing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Performance' }]);
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

        return api
            .get('/hrms/performance/cycles')
            .then(({ data }) => setCycles(data.cycles ?? []))
            .catch(fail('Unable to load cycles.'));
    }, [fail]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function create(e) {
        e.preventDefault();
        setFormErrors({});

        try {
            await api.post('/hrms/performance/cycles', form);
            toast.success('Cycle created in goal setting.');
            setModal(false);
            setForm(emptyCycle);
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function transition(cycle, action, done) {
        setActing(`${cycle.id}:${action}`);

        try {
            await api.post(`/hrms/performance/cycles/${cycle.id}/${action}`, {});
            toast.success(done);
            load();
        } catch {
            setError('That move was refused — weights may not total 100, or the stage already moved.');
        } finally {
            setActing(null);
        }
    }

    function stepper(stage) {
        const at = STAGES.indexOf(stage);

        return (
            <ol className="mt-2 flex flex-wrap items-center gap-1">
                {STAGES.map((step, i) => (
                    <li key={step} className="flex items-center gap-1">
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${i < at ? 'bg-green-100 text-green-800' : i === at ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-400'}`}
                        >
                            {STAGE_LABELS[step]}
                        </span>
                        {i < STAGES.length - 1 && <span className="text-gray-300">›</span>}
                    </li>
                ))}
            </ol>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Performance</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {cycles ? `${cycles.length} cycle${cycles.length === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>
                {canManage && <Button onClick={() => setModal(true)}>New cycle</Button>}
            </div>

            {error && <Alert>{error}</Alert>}

            {!cycles ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : cycles.length === 0 ? (
                <EmptyState title="No cycles yet" hint="Open one to start goal setting." />
            ) : (
                <div className="grid gap-4">
                    {cycles.map((cycle) => {
                        const next = NEXT_STEP[cycle.stage];

                        return (
                            <Card key={cycle.id} dense>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link to={`/hrms/performance/cycles/${cycle.id}`} className="font-medium text-indigo-600 hover:underline">
                                            {cycle.name}
                                        </Link>
                                        <p className="text-xs text-gray-400">
                                            {cycle.period_start} → {cycle.period_end} · {cycle.anonymity} anonymity
                                        </p>
                                        {stepper(cycle.stage)}
                                    </div>
                                    {canManage && next && (
                                        <Button
                                            size="sm"
                                            variant={next.action === 'complete' ? 'warning' : 'secondary'}
                                            disabled={acting !== null}
                                            loading={acting === `${cycle.id}:${next.action}`}
                                            onClick={() => transition(cycle, next.action, next.done)}
                                        >
                                            {next.label}
                                        </Button>
                                    )}
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            <Modal open={modal} onClose={() => setModal(false)} title="New review cycle">
                <form onSubmit={create} className="grid gap-3">
                    <Input label="Name" value={form.name} error={formErrors.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Period start" type="date" value={form.period_start} error={formErrors.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} required />
                        <Input label="Period end" type="date" value={form.period_end} error={formErrors.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} required />
                    </div>
                    <Select label="Feedback anonymity" value={form.anonymity} error={formErrors.anonymity} onChange={(e) => setForm({ ...form, anonymity: e.target.value })}>
                        <option value="none">None — everything attributed</option>
                        <option value="reviewer">Reviewer — words shown, names hidden</option>
                        <option value="peer">Peer — peer answers aggregated</option>
                    </Select>
                    <Input label="Description" value={form.description} error={formErrors.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    <div><Button type="submit">Create cycle</Button></div>
                </form>
            </Modal>
        </div>
    );
}
