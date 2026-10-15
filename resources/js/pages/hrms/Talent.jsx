import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import StatusPill from '../../components/ui/StatusPill';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { useConfirm } from '../../components/ui/ConfirmDialog';

// Server enum hexes (ReviewStatus::color): mirrored, never palette names.
const STATUS_COLORS = {
    draft: '#6b7280',
    calibrating: '#d97706',
    final: '#0284cb',
    acknowledged: '#059669',
};

const RATING_OPTIONS = [1, 2, 3, 4, 5].map((value) => ({ value: String(value), label: String(value) }));

/**
 * Talent calibration: the room where HR adjusts review ratings and seals
 * them final. The backend owns the rules (calibration stage only, 1–5
 * humans, sealed stays sealed); this page only collects and surfaces the
 * 422s. The distribution counts reviews per value and the 360 panel counts
 * responses per relation — neither averages people into scores, per the
 * no-score rule the performance pages already follow.
 *
 * The cycle picker rides `hrms.performance.view` (cycles are the
 * performance surface); everything else rides `hrms.talent.manage`. Both
 * intended roles (admin, HR manager) hold the pair.
 */
export default function Talent() {
    usePageTitle('Talent');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();

    const [cycles, setCycles] = useState(null);
    const [cycleId, setCycleId] = useState('');
    const [reviews, setReviews] = useState(null);
    const [distribution, setDistribution] = useState(null);
    const [summary, setSummary] = useState(null);
    const [error, setError] = useState(null);
    const [calibrating, setCalibrating] = useState(null);
    const [rating, setRating] = useState('3');
    const [formErrors, setFormErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Talent' }]);
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
                const list = data.cycles ?? [];
                setCycles(list);

                if (list.length > 0 && !cycleId) {
                    const inCalibration = list.find((cycle) => cycle.stage === 'calibration');
                    setCycleId(String((inCalibration ?? list[0]).id));
                }
            })
            .catch(fail('Unable to load review cycles.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fail]);

    const loadRoom = useCallback(
        (id) => {
            if (!id) return;

            setError(null);

            api.get(`/hrms/talent/cycles/${id}/reviews`)
                .then(({ data }) => setReviews(data.reviews ?? []))
                .catch(fail('Unable to load the calibration grid.'));
            api.get(`/hrms/talent/cycles/${id}/distribution`)
                .then(({ data }) => setDistribution(data.distribution ?? null))
                .catch(fail('Unable to load the distribution.'));
            api.get(`/hrms/talent/cycles/${id}/feedback-summary`)
                .then(({ data }) => setSummary(data.feedback_summary ?? []))
                .catch(fail('Unable to load the 360 summary.'));
        },
        [fail],
    );

    useEffect(() => {
        loadCycles();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        setReviews(null);
        setDistribution(null);
        setSummary(null);
        loadRoom(cycleId);
    }, [cycleId, loadRoom]);

    async function submitCalibrate(e) {
        e.preventDefault();
        setFormErrors({});
        setSaving(true);

        try {
            await api.post(`/hrms/talent/reviews/${calibrating.id}/calibrate`, { rating: Number(rating) });
            toast.success('Review calibrated.');
            setCalibrating(null);
            loadRoom(cycleId);
        } catch (err) {
            setFormErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function finalize(review) {
        const confirmed = await confirm({
            title: 'Finalize this review?',
            message: `Finalize ${review.employee?.name ?? 'this review'}? Final is a seal — the review cannot be recalibrated afterwards.`,
            variant: 'warning',
            confirmLabel: 'Finalize review',
        });
        if (!confirmed) return;

        try {
            await api.post(`/hrms/talent/reviews/${review.id}/finalize`);
            toast.success('Review finalized.');
            loadRoom(cycleId);
        } catch (err) {
            if (err.response?.status === 422) {
                toast.error(err.response.data?.message ?? 'That review cannot be finalized.');
                loadRoom(cycleId);
                return;
            }

            fail('Unable to finalize the review.')(err);
        }
    }

    const formError = formErrors.rating || formErrors.stage || formErrors.form;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-gray-900">Talent &amp; calibration</h1>
                <p className="mt-1 text-sm text-gray-500">
                    Adjust review ratings while the cycle is in calibration, then seal them final.
                </p>
            </div>

            {error && <Alert variant="error">{error}</Alert>}

            {cycles === null ? (
                <Spinner />
            ) : cycles.length === 0 ? (
                <Card>
                    <EmptyState title="No review cycles" hint="Open a performance cycle to calibration first." />
                </Card>
            ) : (
                <>
                    <div className="max-w-xs">
                        <Select
                            label="Review cycle"
                            value={cycleId}
                            onChange={(e) => setCycleId(e.target.value)}
                            options={cycles.map((cycle) => ({ value: String(cycle.id), label: cycle.name }))}
                        />
                    </div>

                    <Card title="Rating distribution">
                        {distribution === null ? (
                            <Spinner />
                        ) : (
                            <div className="space-y-2">
                                {[5, 4, 3, 2, 1].map((value) => {
                                    const count = distribution.by_rating?.[value] ?? 0;
                                    const total = Math.max(distribution.total ?? 0, 1);

                                    return (
                                        <div key={value} className="flex items-center gap-3 text-sm">
                                            <span className="w-6 shrink-0 font-medium text-gray-700">{value}</span>
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
                                                <div
                                                    className="h-full rounded-full bg-blue-500"
                                                    style={{ width: `${Math.round((count / total) * 100)}%` }}
                                                />
                                            </div>
                                            <span className="w-8 shrink-0 text-right text-gray-500">{count}</span>
                                        </div>
                                    );
                                })}
                                <p className="pt-1 text-xs text-gray-500">
                                    {distribution.unrated ?? 0} unrated · {distribution.total ?? 0} reviews
                                </p>
                            </div>
                        )}
                    </Card>

                    <Card title="Calibration grid">
                        {reviews === null ? (
                            <Spinner />
                        ) : reviews.length === 0 ? (
                            <EmptyState title="No reviews filed" hint="Managers file reviews from the performance cycle first." />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-xs uppercase tracking-wide text-gray-500">
                                            <th scope="col" className="px-3 py-2">Employee</th>
                                            <th scope="col" className="px-3 py-2">Self</th>
                                            <th scope="col" className="px-3 py-2">Manager</th>
                                            <th scope="col" className="px-3 py-2">Calibrated</th>
                                            <th scope="col" className="px-3 py-2">Status</th>
                                            <th scope="col" className="px-3 py-2"><span className="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {reviews.map((review) => (
                                            <tr key={review.id} className="border-b last:border-0">
                                                <td className="px-3 py-2 font-medium">{review.employee?.name ?? '—'}</td>
                                                <td className="px-3 py-2">{review.self_rating ?? '—'}</td>
                                                <td className="px-3 py-2">{review.manager_rating ?? '—'}</td>
                                                <td className="px-3 py-2">{review.calibrated_rating ?? '—'}</td>
                                                <td className="px-3 py-2">
                                                    <StatusPill color={STATUS_COLORS[review.status] ?? '#6b7280'} label={review.status} />
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {['final', 'acknowledged'].includes(review.status) ? (
                                                        <span className="text-xs text-gray-400">Sealed</span>
                                                    ) : (
                                                        <span className="inline-flex gap-2">
                                                            <Button
                                                                variant="secondary"
                                                                onClick={() => {
                                                                    setCalibrating(review);
                                                                    setRating(String(review.calibrated_rating ?? review.manager_rating ?? 3));
                                                                    setFormErrors({});
                                                                }}
                                                            >
                                                                Calibrate
                                                            </Button>
                                                            <Button variant="secondary" onClick={() => finalize(review)}>
                                                                Finalize
                                                            </Button>
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card title="360 summary">
                        {summary === null ? (
                            <Spinner />
                        ) : summary.length === 0 ? (
                            <EmptyState title="No feedback yet" hint="Peer and manager responses aggregate here once submitted." />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-xs uppercase tracking-wide text-gray-500">
                                            <th scope="col" className="px-3 py-2">Employee</th>
                                            <th scope="col" className="px-3 py-2">Manager</th>
                                            <th scope="col" className="px-3 py-2">Peers</th>
                                            <th scope="col" className="px-3 py-2">Direct reports</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {summary.map((row) => (
                                            <tr key={row.employee_id} className="border-b last:border-0">
                                                <td className="px-3 py-2 font-medium">{row.employee?.name ?? '—'}</td>
                                                {['manager', 'peer', 'direct_report'].map((relation) => {
                                                    const cell = row.by_relation?.[relation];

                                                    return (
                                                        <td key={relation} className="px-3 py-2">
                                                            {cell ? `${cell.rated_count}/${cell.count} rated${cell.average_rating !== null ? ` · avg ${cell.average_rating}` : ''}` : '—'}
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </>
            )}

            <Modal open={calibrating !== null} onClose={() => setCalibrating(null)} title="Calibrate review">
                {calibrating && (
                    <form onSubmit={submitCalibrate} className="grid gap-3 px-6 py-5">
                        <p className="text-sm text-gray-600">
                            {calibrating.employee?.name} · manager rated {calibrating.manager_rating ?? '—'}.
                            The original stays on record; this records the calibrated outcome beside it.
                        </p>
                        <Select
                            label="Calibrated rating"
                            value={rating}
                            onChange={(e) => setRating(e.target.value)}
                            options={RATING_OPTIONS}
                            error={formError}
                        />
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" type="button" onClick={() => setCalibrating(null)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Saving…' : 'Save calibration'}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
