import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

/**
 * Answering, one question per page with progress.
 *
 * Self-scoped — this rides the module alone like My files, because it
 * only ever shows campaigns the login was invited to. One submission:
 * the button disables after answering, and the backend constrains
 * doubles anyway. Anonymous campaigns never learn the name behind the
 * fingerprint, here or anywhere.
 */
export default function MySurvey() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.mySurvey.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const { campaignId } = useParams();

    const [campaigns, setCampaigns] = useState(null);
    const [form, setForm] = useState(null);
    const [step, setStep] = useState(0);
    const [answers, setAnswers] = useState({});
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.mySurvey.pageTitle') }]);
    }, [setCrumbs, t]);

    const loadList = useCallback(() => {
        return api
            .get('/hrms/engagement/my')
            .then(({ data }) => setCampaigns(data.campaigns ?? []))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.mySurvey.loadFailed'));
            });
    }, [navigate, t]);

    const loadForm = useCallback(() => {
        if (!campaignId) {
            setForm(null);
            return Promise.resolve();
        }

        setError(null);

        return api
            .get(`/hrms/engagement/my/${campaignId}`)
            .then(({ data }) => {
                setForm(data);
                setStep(0);
                setAnswers({});
                setErrors({});
            })
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError(t('hrms.mySurvey.openFailed'));
            });
    }, [campaignId, navigate, t]);

    useEffect(() => {
        loadList();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        loadForm();
    }, [loadForm]);

    function setAnswer(questionId, value) {
        setAnswers((rows) => ({ ...rows, [questionId]: value }));
    }

    async function submit() {
        setErrors({});

        try {
            await api.post(`/hrms/engagement/campaigns/${campaignId}/respond`, {
                answers: (form?.questions ?? []).map((question) => ({
                    question_id: question.id,
                    value: answers[question.id] ?? null,
                })),
            });
            toast.success(t('hrms.mySurvey.recorded'));
            navigate('/hrms/engagement/mine');
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    function renderQuestion(question) {
        const value = answers[question.id] ?? '';

        switch (question.type) {
            case 'text':
                return (
                    <textarea
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                        rows={4}
                        value={value}
                        aria-label={question.text}
                        placeholder={t('hrms.mySurvey.typePlaceholder')}
                        onChange={(e) => setAnswer(question.id, e.target.value)}
                    />
                );
            case 'multiple_choice':
                return (
                    <div className="grid gap-2">
                        {(question.options ?? []).map((option) => (
                            <label key={option} className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={Array.isArray(value) ? value.includes(option) : false}
                                    onChange={(e) => {
                                        const rows = Array.isArray(value) ? value : [];
                                        setAnswer(question.id, e.target.checked ? [...rows, option] : rows.filter((o) => o !== option));
                                    }}
                                />
                                {option}
                            </label>
                        ))}
                    </div>
                );
            case 'yes_no':
                return (
                    <div className="flex gap-4">
                        {[{ v: 1, l: t('hrms.common.yes') }, { v: 0, l: t('hrms.common.no') }].map((opt) => (
                            <label key={opt.v} className="flex items-center gap-2 text-sm">
                                <input type="radio" name={`q-${question.id}`} checked={Number(value) === opt.v} onChange={() => setAnswer(question.id, opt.v)} />
                                {opt.l}
                            </label>
                        ))}
                    </div>
                );
            default:
                return (
                    <div className="flex flex-wrap gap-2">
                        {Array.from({ length: question.type === 'nps' ? 11 : 5 }, (_, i) => {
                            const n = question.type === 'nps' ? i : i + 1;
                            return (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => setAnswer(question.id, n)}
                                    className={`h-10 w-10 rounded-lg border text-sm font-medium ${Number(value) === n ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]' : 'border-gray-300 text-gray-600 hover:border-gray-400'}`}
                                >
                                    {n}
                                </button>
                            );
                        })}
                    </div>
                );
        }
    }

    if (campaignId) {
        if (!form) {
            return (
                <div className="space-y-4">
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.mySurvey.survey')}</h2>
                    {error && <Alert>{error}</Alert>}
                    <ContentSkeleton size="md" />
                </div>
            );
        }

        if (form.answered) {
            return (
                <div className="space-y-4">
                    <h2 className="text-xl font-semibold text-gray-900">{form.campaign?.name}</h2>
                    <Card dense>
                        <p className="text-sm text-gray-600">{t('hrms.mySurvey.answered')}</p>
                        <div className="mt-3">
                            <Button variant="secondary" onClick={() => navigate('/hrms/engagement/mine')}>{t('hrms.mySurvey.backToMine')}</Button>
                        </div>
                    </Card>
                </div>
            );
        }

        const questions = form.questions ?? [];
        const question = questions[step];
        const last = step >= questions.length - 1;

        return (
            <div className="mx-auto max-w-xl space-y-4">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{form.campaign?.name}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {t('hrms.mySurvey.progress', { n: Math.min(step + 1, questions.length), total: questions.length })}
                        {form.campaign?.template?.is_anonymous ? t('hrms.mySurvey.anonymousSuffix') : ''}
                    </p>
                </div>

                {error && <Alert>{error}</Alert>}

                <div className="h-2 overflow-hidden rounded-full bg-gray-100">
                    <div className="h-full rounded-full bg-[var(--accent-soft)] transition-all" style={{ width: `${questions.length === 0 ? 0 : ((step + 1) / questions.length) * 100}%` }} />
                </div>

                {question ? (
                    <Card dense>
                        <p className="mb-1 text-sm font-medium text-gray-900">
                            {question.text}
                            {question.is_required && <span className="ml-1 text-red-500">*</span>}
                        </p>
                        {renderQuestion(question)}
                        {errors[`questions.${question.id}`] && <p className="mt-1.5 text-sm text-red-600">{errors[`questions.${question.id}`]}</p>}
                        {errors.answers && <p className="mt-1.5 text-sm text-red-600">{errors.answers}</p>}
                        {errors.form && <Alert>{errors.form}</Alert>}

                        <div className="mt-4 flex justify-between">
                            <Button variant="secondary" disabled={step === 0} onClick={() => setStep((s) => s - 1)}>
                                {t('hrms.mySurvey.back')}
                            </Button>
                            {last ? (
                                <Button onClick={submit}>{t('hrms.mySurvey.submitAnswers')}</Button>
                            ) : (
                                <Button onClick={() => setStep((s) => s + 1)}>{t('hrms.mySurvey.next')}</Button>
                            )}
                        </div>
                    </Card>
                ) : (
                    <EmptyState title={t('hrms.mySurvey.noQuestionsTitle')} hint={t('hrms.mySurvey.noQuestionsHint')} />
                )}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.mySurvey.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">{t('hrms.mySurvey.subtitle')}</p>
            </div>

            {error && <Alert>{error}</Alert>}

            {!campaigns ? (
                <ContentSkeleton size="md" />
            ) : campaigns.length === 0 ? (
                <EmptyState title={t('hrms.mySurvey.emptyTitle')} hint={t('hrms.mySurvey.emptyHint')} />
            ) : (
                <Card dense>
                    <ul className="divide-y divide-gray-100">
                        {campaigns.map((campaign) => (
                            <li key={campaign.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <p className="font-medium text-gray-900">{campaign.name}</p>
                                    <p className="text-xs text-gray-400">
                                        {campaign.answered ? t('hrms.mySurvey.answeredState') : t('hrms.mySurvey.waitingOnYou')}
                                        {campaign.ends_at ? t('hrms.mySurvey.closesSuffix', { date: campaign.ends_at }) : ''}
                                    </p>
                                </div>
                                {!campaign.answered && (
                                    <Button size="sm" onClick={() => navigate(`/hrms/engagement/mine/${campaign.id}`)}>
                                        {t('hrms.mySurvey.answer')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}
