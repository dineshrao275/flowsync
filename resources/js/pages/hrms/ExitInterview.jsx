import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Alert from '../../components/ui/Alert';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Textarea from '../../components/ui/Textarea';
import { fieldClass } from '../../components/ui/fieldStyles';

function Question({ q, value, onChange, error }) {
    const { t } = useTranslation();
    const label = `${q.label}${q.required ? ' *' : ''}`;
    const id = `q-${q.key}`;

    if (q.type === 'scale') {
        return (
            <fieldset>
                <legend className="mb-1.5 text-sm font-medium text-gray-700">{label}</legend>
                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={q.label}>
                    {[1, 2, 3, 4, 5].map((n) => (
                        <button key={n} type="button" role="radio" aria-checked={value === n} onClick={() => onChange(n)}
                            className={`h-9 w-9 rounded-full border text-sm font-medium ${value === n ? 'border-[var(--accent)] bg-[var(--accent)] text-[var(--accent-contrast)]' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'}`}>{n}</button>
                    ))}
                    <span className="self-center text-xs text-gray-500">{t('hrms.exitInterview.scaleHint')}</span>
                </div>
                {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
            </fieldset>
        );
    }

    if (q.type === 'choice') {
        return (
            <div>
                <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-gray-700">{label}</label>
                <select id={id} className={fieldClass} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
                    <option value="">—</option>
                    {q.options.map((o) => <option key={o} value={o}>{t(`hrms.exitInterview.options.${o}`, { defaultValue: o })}</option>)}
                </select>
                {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
            </div>
        );
    }

    return <Textarea label={label} rows={3} value={value ?? ''} onChange={(e) => onChange(e.target.value)} error={error} />;
}

/** The leaver's own exit interview. */
export default function ExitInterview() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.exitInterview.pageTitle'));
    const toast = useToast();
    const [data, setData] = useState(null);
    const [answers, setAnswers] = useState({});
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => {
        api.get('/hrms/exit-interview').then(({ data: res }) => { setData(res); setAnswers(res.interview?.answers || {}); }).catch(() => setData({ interview: null, questions: [] }));
    }, []);

    useEffect(load, [load]);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.put('/hrms/exit-interview', { answers });
            toast.success(t('hrms.exitInterview.saved'));
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    if (!data) return <ContentSkeleton size="lg" />;

    const interview = data.interview;

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('hrms.exitInterview.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('hrms.exitInterview.subtitle')}</p>
            </div>
            {!interview ? <Alert type="info">{t('hrms.exitInterview.none')}</Alert> : interview.status === 'waived' ? <Alert type="info">{t('hrms.exitInterview.waived')}</Alert> : (
                <Card>
                    {interview.status === 'completed' && <Alert type="success">{t('hrms.exitInterview.completed')}</Alert>}
                    <form onSubmit={submit} className="mt-4 space-y-5">
                        {data.questions.map((q) => (
                            <Question key={q.key} q={q} value={answers[q.key]} error={errors[`answers.${q.key}`]} onChange={(v) => setAnswers((a) => ({ ...a, [q.key]: v }))} />
                        ))}
                        <p className="text-xs text-gray-500">{t('hrms.exitInterview.privacy')}</p>
                        <Button type="submit" loading={saving}>{interview.status === 'completed' ? t('hrms.exitInterview.update') : t('hrms.exitInterview.submit')}</Button>
                    </form>
                </Card>
            )}
        </div>
    );
}
