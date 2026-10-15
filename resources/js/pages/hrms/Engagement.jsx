import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import ResultsChart from '../../components/hrms/ResultsChart';

const TYPES = ['pulse', 'engagement', 'onboarding_exit', 'exit', 'custom'];
const QUESTION_TYPES = ['scale', 'text', 'multiple_choice', 'yes_no', 'nps'];
const FREQUENCIES = ['one_time', 'weekly', 'monthly', 'quarterly', 'annual'];
const AUDIENCES = ['all', 'department', 'role', 'location', 'explicit'];

const emptyTemplate = { name: '', description: '', type: 'pulse', is_anonymous: false, frequency: 'one_time', audience_scope: 'all' };
const emptyQuestion = { text: '', type: 'scale', options: '', is_required: false, min: '', max: '' };

/**
 * Questionnaires, campaigns, and their aggregates.
 *
 * A manager screen end to end — reads ride the view permission, every
 * mutation hides without manage (the backend 403s regardless). Results
 * render what the threshold released: below it the service returns counts
 * with no rows, and this page shows the headcount instead of an empty
 * chart that reads as broken.
 */
export default function Engagement() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.engagement.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('hrms.engagement.manage');

    const [templates, setTemplates] = useState(null);
    const [campaigns, setCampaigns] = useState(null);
    const [error, setError] = useState(null);

    const [templateModal, setTemplateModal] = useState(false);
    const [templateForm, setTemplateForm] = useState(emptyTemplate);
    const [questions, setQuestions] = useState([{ ...emptyQuestion }]);
    const [templateErrors, setTemplateErrors] = useState({});

    const [campaignModal, setCampaignModal] = useState(false);
    const [campaignForm, setCampaignForm] = useState({ template_id: '', name: '', starts_at: '', ends_at: '', anonymity_threshold: '5' });
    const [campaignErrors, setCampaignErrors] = useState({});

    const [resultsFor, setResultsFor] = useState(null);
    const [results, setResults] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.engagement.pageTitle') }]);
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
            api.get('/hrms/engagement/templates').then(({ data }) => setTemplates(data.templates ?? [])),
            api.get('/hrms/engagement/campaigns').then(({ data }) => setCampaigns(data.campaigns ?? [])),
        ]).catch(fail(t('hrms.engagement.loadFailed')));
    }, [fail, t]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function saveTemplate(e) {
        e.preventDefault();
        setTemplateErrors({});

        try {
            await api.post('/hrms/engagement/templates', {
                ...templateForm,
                questions: questions
                    .filter((q) => q.text.trim() !== '')
                    .map((q, i) => ({
                        ...q,
                        options: q.type === 'multiple_choice' && q.options.trim() !== ''
                            ? q.options.split('\n').map((o) => o.trim()).filter(Boolean)
                            : null,
                        min: q.min === '' ? null : q.min,
                        max: q.max === '' ? null : q.max,
                        sequence: (i + 1) * 10,
                    })),
            });
            toast.success(t('hrms.engagement.templateCreated'));
            setTemplateModal(false);
            setTemplateForm(emptyTemplate);
            setQuestions([{ ...emptyQuestion }]);
            load();
        } catch (err) {
            setTemplateErrors(fieldErrors(err));
        }
    }

    async function saveCampaign(e) {
        e.preventDefault();
        setCampaignErrors({});

        try {
            await api.post('/hrms/engagement/campaigns', {
                ...campaignForm,
                template_id: Number(campaignForm.template_id),
                anonymity_threshold: Number(campaignForm.anonymity_threshold) || 5,
                starts_at: campaignForm.starts_at || null,
                ends_at: campaignForm.ends_at || null,
            });
            toast.success(t('hrms.engagement.campaignScheduled'));
            setCampaignModal(false);
            setCampaignForm({ template_id: '', name: '', starts_at: '', ends_at: '', anonymity_threshold: '5' });
            load();
        } catch (err) {
            setCampaignErrors(fieldErrors(err));
        }
    }

    async function transition(campaign, action, done) {
        try {
            await api.post(`/hrms/engagement/campaigns/${campaign.id}/${action}`, {});
            toast.success(done);
            load();
        } catch {
            setError(t('hrms.engagement.moveRefused'));
        }
    }

    async function openResults(campaign) {
        try {
            const { data } = await api.get(`/hrms/engagement/campaigns/${campaign.id}/results`);
            setResultsFor(campaign);
            setResults(data);
        } catch {
            setError(t('hrms.engagement.resultsFailed'));
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.engagement.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">{t('hrms.engagement.subtitle')}</p>
                </div>
                {canManage && (
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={() => setCampaignModal(true)}>{t('hrms.engagement.scheduleCampaign')}</Button>
                        <Button onClick={() => setTemplateModal(true)}>{t('hrms.engagement.newTemplate')}</Button>
                    </div>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            <Card title={t('hrms.engagement.campaigns')} dense>
                {!campaigns ? (
                    <div className="flex justify-center py-8"><Spinner /></div>
                ) : campaigns.length === 0 ? (
                    <EmptyState title={t('hrms.engagement.noCampaignsTitle')} hint={t('hrms.engagement.noCampaignsHint')} />
                ) : (
                    <Table>
                        <thead>
                            <tr><Th>{t('hrms.engagement.thCampaign')}</Th><Th>{t('hrms.engagement.thStatus')}</Th><Th>{t('hrms.engagement.thWindow')}</Th><Th><span className="sr-only">{t('hrms.engagement.thActions')}</span></Th></tr>
                        </thead>
                        <tbody>
                            {campaigns.map((campaign) => (
                                <tr key={campaign.id}>
                                    <Td>
                                        <span className="font-medium text-gray-900">{campaign.name}</span>
                                        <span className="block text-xs text-gray-400">{campaign.template?.name ?? ''}{campaign.template?.is_anonymous ? t('hrms.engagement.anonymousSuffix') : ''}</span>
                                    </Td>
                                    <Td>{campaign.status}</Td>
                                    <Td>{campaign.starts_at ?? '—'} → {campaign.ends_at ?? '—'}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-2">
                                            <Button size="sm" variant="secondary" onClick={() => openResults(campaign)}>{t('hrms.engagement.results')}</Button>
                                            {canManage && campaign.status === 'scheduled' && (
                                                <Button size="sm" variant="secondary" onClick={() => transition(campaign, 'open', t('hrms.engagement.campaignOpened'))}>{t('hrms.engagement.open')}</Button>
                                            )}
                                            {canManage && campaign.status === 'open' && (
                                                <>
                                                    <Button size="sm" variant="secondary" onClick={() => transition(campaign, 'invite', t('hrms.engagement.audienceInvited'))}>{t('hrms.engagement.invite')}</Button>
                                                    <Button size="sm" variant="warning" onClick={() => transition(campaign, 'close', t('hrms.engagement.campaignClosed'))}>{t('hrms.engagement.close')}</Button>
                                                </>
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card title={t('hrms.engagement.templates')} dense>
                {!templates ? (
                    <div className="flex justify-center py-8"><Spinner /></div>
                ) : templates.length === 0 ? (
                    <EmptyState title={t('hrms.engagement.noTemplatesTitle')} />
                ) : (
                    <ul className="divide-y divide-gray-100 text-sm">
                        {templates.map((template) => (
                            <li key={template.id} className="py-2.5">
                                <p className="font-medium text-gray-900">{template.name}</p>
                                <p className="text-xs text-gray-400">
                                    {t('hrms.engagement.templateMeta', {
                                        type: template.type,
                                        audience: template.audience_scope,
                                        count: (template.questions ?? []).length,
                                    })}
                                    {template.is_anonymous ? t('hrms.engagement.anonymousSuffix') : ''}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={!!resultsFor} onClose={() => setResultsFor(null)} title={t('hrms.engagement.resultsTitle', { name: resultsFor?.name ?? '' })} size="lg">
                {!results ? (
                    <div className="flex justify-center px-6 py-6"><Spinner /></div>
                ) : results.results.length === 0 ? (
                    <p className="px-6 py-5 text-sm text-gray-500">
                        {t('hrms.engagement.belowThreshold', { count: results.response_count, threshold: results.anonymity_threshold })}
                    </p>
                ) : (
                    <div className="space-y-5 px-6 py-5">
                        {results.results.map((row) => (
                            <div key={row.question_id}>
                                <p className="mb-1 text-sm font-medium text-gray-900">{row.text}</p>
                                <ResultsChart result={row} />
                            </div>
                        ))}
                    </div>
                )}
            </Modal>

            <Modal open={templateModal} onClose={() => setTemplateModal(false)} title={t('hrms.engagement.newTemplateTitle')} size="lg">
                <form onSubmit={saveTemplate} className="grid gap-3 px-6 py-5">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label={t('hrms.engagement.name')} value={templateForm.name} error={templateErrors.name} onChange={(e) => setTemplateForm({ ...templateForm, name: e.target.value })} required />
                        <Select label={t('hrms.engagement.type')} value={templateForm.type} error={templateErrors.type} onChange={(e) => setTemplateForm({ ...templateForm, type: e.target.value })}>
                            {TYPES.map((value) => <option key={value} value={value}>{t(`hrms.engagement.types.${value}`)}</option>)}
                        </Select>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Select label={t('hrms.engagement.frequency')} value={templateForm.frequency} error={templateErrors.frequency} onChange={(e) => setTemplateForm({ ...templateForm, frequency: e.target.value })}>
                            {FREQUENCIES.map((f) => <option key={f} value={f}>{t(`hrms.engagement.frequencies.${f}`)}</option>)}
                        </Select>
                        <Select label={t('hrms.engagement.audience')} value={templateForm.audience_scope} error={templateErrors.audience_scope} onChange={(e) => setTemplateForm({ ...templateForm, audience_scope: e.target.value })}>
                            {AUDIENCES.map((s) => <option key={s} value={s}>{t(`hrms.engagement.audiences.${s}`)}</option>)}
                        </Select>
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <input type="checkbox" checked={templateForm.is_anonymous} onChange={(e) => setTemplateForm({ ...templateForm, is_anonymous: e.target.checked })} />
                            {t('hrms.engagement.anonymous')}
                        </label>
                    </div>

                    <h4 className="text-sm font-semibold text-gray-900">{t('hrms.engagement.questions')}</h4>
                    {questions.map((question, index) => (
                        <div key={index} className="rounded-lg border border-gray-200 p-3">
                            <Input label={t('hrms.engagement.question', { n: index + 1 })} value={question.text} onChange={(e) => setQuestions((rows) => rows.map((r, i) => (i === index ? { ...r, text: e.target.value } : r)))} required={index === 0} />
                            <div className="mt-2 grid gap-3 sm:grid-cols-3">
                                <Select label={t('hrms.engagement.type')} value={question.type} onChange={(e) => setQuestions((rows) => rows.map((r, i) => (i === index ? { ...r, type: e.target.value } : r)))}>
                                    {QUESTION_TYPES.map((value) => <option key={value} value={value}>{t(`hrms.engagement.questionTypes.${value}`)}</option>)}
                                </Select>
                                <Input label={t('hrms.engagement.min')} value={question.min} onChange={(e) => setQuestions((rows) => rows.map((r, i) => (i === index ? { ...r, min: e.target.value } : r)))} />
                                <Input label={t('hrms.engagement.max')} value={question.max} onChange={(e) => setQuestions((rows) => rows.map((r, i) => (i === index ? { ...r, max: e.target.value } : r)))} />
                            </div>
                            {question.type === 'multiple_choice' && (
                                <div className="mt-2">
                                    <label htmlFor={`question-options-${index}`} className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.engagement.options')}</label>
                                    <textarea
                                        id={`question-options-${index}`}
                                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                                        rows={3}
                                        value={question.options}
                                        onChange={(e) => setQuestions((rows) => rows.map((r, i) => (i === index ? { ...r, options: e.target.value } : r)))}
                                    />
                                </div>
                            )}
                            <div className="mt-2 flex items-center justify-between">
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={question.is_required} onChange={(e) => setQuestions((rows) => rows.map((r, i) => (i === index ? { ...r, is_required: e.target.checked } : r)))} />
                                    {t('hrms.engagement.required')}
                                </label>
                                {questions.length > 1 && (
                                    <Button type="button" size="sm" variant="danger" onClick={() => setQuestions((rows) => rows.filter((_, i) => i !== index))}>
                                        {t('hrms.engagement.remove')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}
                    <div>
                        <Button type="button" variant="secondary" onClick={() => setQuestions((rows) => [...rows, { ...emptyQuestion }])}>
                            {t('hrms.engagement.addQuestion')}
                        </Button>
                    </div>
                    <div><Button type="submit">{t('hrms.engagement.createTemplate')}</Button></div>
                </form>
            </Modal>

            <Modal open={campaignModal} onClose={() => setCampaignModal(false)} title={t('hrms.engagement.campaignModalTitle')}>
                <form onSubmit={saveCampaign} className="grid gap-3 px-6 py-5">
                    <Select label={t('hrms.engagement.template')} value={campaignForm.template_id} error={campaignErrors.template_id} onChange={(e) => setCampaignForm({ ...campaignForm, template_id: e.target.value })} required>
                        <option value="">{t('hrms.engagement.select')}</option>
                        {(templates ?? []).map((tpl) => <option key={tpl.id} value={tpl.id}>{tpl.name}</option>)}
                    </Select>
                    <Input label={t('hrms.engagement.name')} value={campaignForm.name} error={campaignErrors.name} onChange={(e) => setCampaignForm({ ...campaignForm, name: e.target.value })} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label={t('hrms.engagement.startsAt')} type="date" value={campaignForm.starts_at} error={campaignErrors.starts_at} onChange={(e) => setCampaignForm({ ...campaignForm, starts_at: e.target.value })} />
                        <Input label={t('hrms.engagement.endsAt')} type="date" value={campaignForm.ends_at} error={campaignErrors.ends_at} onChange={(e) => setCampaignForm({ ...campaignForm, ends_at: e.target.value })} />
                    </div>
                    <Input label={t('hrms.engagement.anonymityThreshold')} value={campaignForm.anonymity_threshold} error={campaignErrors.anonymity_threshold} onChange={(e) => setCampaignForm({ ...campaignForm, anonymity_threshold: e.target.value })} />
                    <div><Button type="submit">{t('hrms.engagement.schedule')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
