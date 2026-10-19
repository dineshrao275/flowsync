import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { useConfirm } from '../../components/ui/ConfirmDialog';

const STAGES = ['goal_setting', 'check_in', 'self_review', 'manager_review', 'calibration', 'completed'];
const NEXT_ACTIONS = {
    goal_setting: 'open-check-in',
    check_in: 'open-self-review',
    self_review: 'open-manager-review',
    manager_review: 'open-calibration',
    calibration: 'complete',
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
    const { t } = useTranslation();
    usePageTitle(t('hrms.performance.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();

    const canManage = can('hrms.performance.manage');

    const [cycles, setCycles] = useState(null);
    const [error, setError] = useState(null);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState(emptyCycle);
    const [formErrors, setFormErrors] = useState({});
    const [acting, setActing] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.performance.pageTitle') }]);
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

        return api
            .get('/hrms/performance/cycles')
            .then(({ data }) => setCycles(data.cycles ?? []))
            .catch(fail(t('hrms.performance.loadFailed')));
    }, [fail, t]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function create(e) {
        e.preventDefault();
        setFormErrors({});

        try {
            await api.post('/hrms/performance/cycles', form);
            toast.success(t('hrms.performance.created'));
            setModal(false);
            setForm(emptyCycle);
            load();
        } catch (err) {
            setFormErrors(fieldErrors(err));
        }
    }

    async function transition(cycle, action) {
        const isComplete = action === 'complete';
        const label = t(`hrms.performance.next.${cycle.stage}.label`);
        const done = t(`hrms.performance.next.${cycle.stage}.done`);
        const confirmed = await confirm({
            title: isComplete ? t('hrms.performance.confirmCompleteTitle') : t('hrms.performance.confirmAdvanceTitle', { label }),
            message: isComplete
                ? t('hrms.performance.confirmCompleteMessage', { name: cycle.name })
                : t('hrms.performance.confirmAdvanceMessage', { name: cycle.name }),
            variant: isComplete ? 'warning' : 'primary',
            confirmLabel: isComplete ? t('hrms.performance.confirmCompleteLabel') : label,
        });
        if (!confirmed) return;

        setActing(`${cycle.id}:${action}`);

        try {
            await api.post(`/hrms/performance/cycles/${cycle.id}/${action}`, {});
            toast.success(done);
            load();
        } catch {
            setError(t('hrms.performance.advanceFailed'));
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
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${i < at ? 'bg-green-100 text-green-800' : i === at ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]' : 'bg-gray-100 text-gray-400'}`}
                        >
                            {t(`hrms.performance.stage.${step}`)}
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
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.performance.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {cycles ? (cycles.length === 1 ? t('hrms.performance.cycleCountOne') : t('hrms.performance.cycleCount', { count: cycles.length })) : '—'}
                    </p>
                </div>
                {canManage && <Button onClick={() => setModal(true)}>{t('hrms.performance.newCycle')}</Button>}
            </div>

            {error && <Alert>{error}</Alert>}

            {!cycles ? (
                <ContentSkeleton size="md" />
            ) : cycles.length === 0 ? (
                <EmptyState title={t('hrms.performance.emptyTitle')} hint={t('hrms.performance.emptyHint')} />
            ) : (
                <div className="grid gap-4">
                    {cycles.map((cycle) => {
                        const action = NEXT_ACTIONS[cycle.stage];

                        return (
                            <Card key={cycle.id} dense>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link to={`/hrms/performance/cycles/${cycle.id}`} className="font-medium text-[var(--accent-soft-text)] hover:underline">
                                            {cycle.name}
                                        </Link>
                                        <p className="text-xs text-gray-400">
                                            {t('hrms.performance.meta', { start: cycle.period_start, end: cycle.period_end, anonymity: cycle.anonymity })}
                                        </p>
                                        {stepper(cycle.stage)}
                                    </div>
                                    {canManage && action && (
                                        <Button
                                            size="sm"
                                            variant={action === 'complete' ? 'warning' : 'secondary'}
                                            disabled={acting !== null}
                                            loading={acting === `${cycle.id}:${action}`}
                                            onClick={() => transition(cycle, action)}
                                        >
                                            {t(`hrms.performance.next.${cycle.stage}.label`)}
                                        </Button>
                                    )}
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            <Modal open={modal} onClose={() => setModal(false)} title={t('hrms.performance.newCycleTitle')}>
                <form onSubmit={create} className="grid gap-3 px-6 py-5">
                    <Input label={t('hrms.performance.name')} value={form.name} error={formErrors.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label={t('hrms.performance.periodStart')} type="date" value={form.period_start} error={formErrors.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} required />
                        <Input label={t('hrms.performance.periodEnd')} type="date" value={form.period_end} error={formErrors.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} required />
                    </div>
                    <Select label={t('hrms.performance.anonymity')} value={form.anonymity} error={formErrors.anonymity} onChange={(e) => setForm({ ...form, anonymity: e.target.value })}>
                        <option value="none">{t('hrms.performance.anonymityNone')}</option>
                        <option value="reviewer">{t('hrms.performance.anonymityReviewer')}</option>
                        <option value="peer">{t('hrms.performance.anonymityPeer')}</option>
                    </Select>
                    <Input label={t('hrms.performance.description')} value={form.description} error={formErrors.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    <div><Button type="submit">{t('hrms.performance.createCycle')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
