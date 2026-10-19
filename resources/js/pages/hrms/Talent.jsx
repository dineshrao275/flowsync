import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
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
    const { t } = useTranslation();
    usePageTitle(t('hrms.talent.pageTitle'));
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
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.talent.pageTitle') }]);
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
            .catch(fail(t('hrms.talent.loadCyclesFailed')));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fail, t]);

    const loadRoom = useCallback(
        (id) => {
            if (!id) return;

            setError(null);

            api.get(`/hrms/talent/cycles/${id}/reviews`)
                .then(({ data }) => setReviews(data.reviews ?? []))
                .catch(fail(t('hrms.talent.loadGridFailed')));
            api.get(`/hrms/talent/cycles/${id}/distribution`)
                .then(({ data }) => setDistribution(data.distribution ?? null))
                .catch(fail(t('hrms.talent.loadDistributionFailed')));
            api.get(`/hrms/talent/cycles/${id}/feedback-summary`)
                .then(({ data }) => setSummary(data.feedback_summary ?? []))
                .catch(fail(t('hrms.talent.loadSummaryFailed')));
        },
        [fail, t],
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
            toast.success(t('hrms.talent.calibrated'));
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
            title: t('hrms.talent.finalizeTitle'),
            message: t('hrms.talent.finalizeMessage', { name: review.employee?.name ?? t('hrms.talent.finalizeFallbackName') }),
            variant: 'warning',
            confirmLabel: t('hrms.talent.finalizeLabel'),
        });
        if (!confirmed) return;

        try {
            await api.post(`/hrms/talent/reviews/${review.id}/finalize`);
            toast.success(t('hrms.talent.finalized'));
            loadRoom(cycleId);
        } catch (err) {
            if (err.response?.status === 422) {
                toast.error(err.response.data?.message ?? t('hrms.talent.finalizeFailed'));
                loadRoom(cycleId);
                return;
            }

            fail(t('hrms.talent.unableToFinalize'))(err);
        }
    }

    const formError = formErrors.rating || formErrors.stage || formErrors.form;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-gray-900">{t('hrms.talent.heading')}</h1>
                <p className="mt-1 text-sm text-gray-500">
                    {t('hrms.talent.subtitle')}
                </p>
            </div>

            {error && <Alert variant="error">{error}</Alert>}

            {cycles === null ? (
                <ContentSkeleton size="sm" />
            ) : cycles.length === 0 ? (
                <Card>
                    <EmptyState title={t('hrms.talent.emptyCyclesTitle')} hint={t('hrms.talent.emptyCyclesHint')} />
                </Card>
            ) : (
                <>
                    <div className="max-w-xs">
                        <Select
                            label={t('hrms.talent.reviewCycle')}
                            value={cycleId}
                            onChange={(e) => setCycleId(e.target.value)}
                            options={cycles.map((cycle) => ({ value: String(cycle.id), label: cycle.name }))}
                        />
                    </div>

                    <Card title={t('hrms.talent.ratingDistribution')}>
                        {distribution === null ? (
                            <ContentSkeleton size="sm" />
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
                                    {t('hrms.talent.unratedCount', { unrated: distribution.unrated ?? 0, total: distribution.total ?? 0 })}
                                </p>
                            </div>
                        )}
                    </Card>

                    <Card title={t('hrms.talent.calibrationGrid')}>
                        {reviews === null ? (
                            <ContentSkeleton size="sm" />
                        ) : reviews.length === 0 ? (
                            <EmptyState title={t('hrms.talent.noReviewsTitle')} hint={t('hrms.talent.noReviewsHint')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-xs uppercase tracking-wide text-gray-500">
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thEmployee')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thSelf')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thManager')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thCalibrated')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thStatus')}</th>
                                            <th scope="col" className="px-3 py-2"><span className="sr-only">{t('hrms.talent.thActions')}</span></th>
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
                                                        <span className="text-xs text-gray-400">{t('hrms.talent.sealed')}</span>
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
                                                                {t('hrms.talent.calibrate')}
                                                            </Button>
                                                            <Button variant="secondary" onClick={() => finalize(review)}>
                                                                {t('hrms.talent.finalize')}
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

                    <Card title={t('hrms.talent.summary360')}>
                        {summary === null ? (
                            <ContentSkeleton size="sm" />
                        ) : summary.length === 0 ? (
                            <EmptyState title={t('hrms.talent.noFeedbackTitle')} hint={t('hrms.talent.noFeedbackHint')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-xs uppercase tracking-wide text-gray-500">
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thEmployee')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thManager')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thPeers')}</th>
                                            <th scope="col" className="px-3 py-2">{t('hrms.talent.thDirectReports')}</th>
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
                                                            {cell ? `${t('hrms.talent.ratedCell', { rated: cell.rated_count, count: cell.count })}${cell.average_rating !== null ? t('hrms.talent.ratedAvgSuffix', { avg: cell.average_rating }) : ''}` : '—'}
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

            <Modal open={calibrating !== null} onClose={() => setCalibrating(null)} title={t('hrms.talent.calibrateModalTitle')}>
                {calibrating && (
                    <form onSubmit={submitCalibrate} className="grid gap-3 px-6 py-5">
                        <p className="text-sm text-gray-600">
                            {t('hrms.talent.calibrateInfo', { name: calibrating.employee?.name, rating: calibrating.manager_rating ?? '—' })}
                            {' '}{t('hrms.talent.calibrateNote')}
                        </p>
                        <Select
                            label={t('hrms.talent.calibratedRating')}
                            value={rating}
                            onChange={(e) => setRating(e.target.value)}
                            options={RATING_OPTIONS}
                            error={formError}
                        />
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" type="button" onClick={() => setCalibrating(null)}>
                                {t('hrms.common.cancel')}
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? t('hrms.talent.saving') : t('hrms.talent.saveCalibration')}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
