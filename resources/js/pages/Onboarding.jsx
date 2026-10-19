import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import usePageTitle from '../hooks/usePageTitle';

export default function Onboarding() {
    const { t } = useTranslation();
    usePageTitle(t('auth.pageTitles.onboarding'));
    const { user, refresh } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [onboarding, setOnboarding] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [working, setWorking] = useState(null);
    const [business, setBusiness] = useState({ legal_name: '', industry: '', company_size: '', country: '', website: '' });
    const [businessErrors, setBusinessErrors] = useState({});
    const [businessOpen, setBusinessOpen] = useState(false);
    const [hrms, setHrms] = useState({ company_size: '', industry: '', office_name: '', office_city: '', office_country: '' });
    const [hrmsErrors, setHrmsErrors] = useState({});
    const [hrmsOpen, setHrmsOpen] = useState(false);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/onboarding');
            setOnboarding(data.onboarding);
        } catch (err) {
            setError(fieldErrors(err).form || t('auth.onboarding.loadFailed'));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (onboarding?.complete && !loading) {
            refresh();
            navigate('/dashboard', { replace: true });
        }
    }, [onboarding, loading, navigate, refresh]);

    if (loading) {
        return (
            <ContentSkeleton size="lg" />
        );
    }

    const steps = onboarding?.steps ?? [];
    const done = steps.filter((step) => step.complete).length;
    const progress = steps.length ? Math.round((done / steps.length) * 100) : 0;

    async function markStep(step) {
        setWorking(step);
        setError(null);
        try {
            const { data } = await api.put('/onboarding/step', { step });
            setOnboarding(data.onboarding);
        } catch (err) {
            setError(fieldErrors(err).form || t('auth.onboarding.stepFailed', { step }));
        } finally {
            setWorking(null);
        }
    }

    async function saveBusiness() {
        setWorking('business');
        setBusinessErrors({});
        try {
            await api.put('/tenant/profile', business);
            const { data } = await api.put('/onboarding/step', { step: 'business' });
            setOnboarding(data.onboarding);
        } catch (err) {
            setBusinessErrors(fieldErrors(err));
        } finally {
            setWorking(null);
        }
    }

    async function saveHrms() {
        setWorking('hrms');
        setHrmsErrors({});
        try {
            await api.put('/tenant/profile', {
                ...(hrms.company_size ? { company_size: hrms.company_size } : {}),
                ...(hrms.industry ? { industry: hrms.industry } : {}),
            });

            if (hrms.office_name.trim() !== '') {
                await api.post('/hrms/locations', {
                    name: hrms.office_name.trim(),
                    ...(hrms.office_city.trim() !== '' ? { city: hrms.office_city.trim() } : {}),
                    ...(hrms.office_country.trim() !== '' ? { country: hrms.office_country.trim() } : {}),
                });
            }

            const { data } = await api.put('/onboarding/step', { step: 'hrms' });
            setOnboarding(data.onboarding);
        } catch (err) {
            setHrmsErrors(fieldErrors(err));
        } finally {
            setWorking(null);
        }
    }

    async function finish() {
        setWorking('completion');
        try {
            const { data } = await api.post('/onboarding/complete');
            setOnboarding(data.onboarding);
            toast.success(t('auth.onboarding.finished'));
            await refresh();
            navigate('/dashboard', { replace: true });
        } catch (err) {
            setError(fieldErrors(err).form || t('auth.onboarding.finishFailed'));
        } finally {
            setWorking(null);
        }
    }

    const stepContent = {
        business: (
            <button
                type="button"
                onClick={() => setBusinessOpen((open) => !open)}
                className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
            >
                {businessOpen ? t('auth.onboarding.hideForm') : t('auth.onboarding.fillBusiness')}
            </button>
        ),
        admin: (
            <Button size="sm" onClick={() => markStep('admin')} loading={working === 'admin'}>
                {t('auth.onboarding.markDone')}
            </Button>
        ),
        subscription: (
            <Button size="sm" onClick={() => markStep('subscription')} loading={working === 'subscription'}>
                {t('auth.onboarding.useDefaultPlan')}
            </Button>
        ),
        configuration: (
            <Button size="sm" variant="secondary" onClick={() => markStep('configuration')} loading={working === 'configuration'}>
                {t('auth.onboarding.setupLater')}
            </Button>
        ),
        hrms: (
            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={() => setHrmsOpen((open) => !open)}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                >
                    {hrmsOpen ? t('auth.onboarding.hideForm') : t('auth.onboarding.setupPeople')}
                </button>
                <Button size="sm" variant="secondary" onClick={() => markStep('hrms')} loading={working === 'hrms'}>
                    {t('auth.onboarding.later')}
                </Button>
            </div>
        ),
        verification: (
            <Button size="sm" variant="secondary" onClick={() => markStep('verification')} loading={working === 'verification'}>
                {t('auth.onboarding.confirmDetails')}
            </Button>
        ),
        completion: (
            <Button size="sm" onClick={finish} loading={working === 'completion'}>
                {t('auth.onboarding.finishSetup')}
            </Button>
        ),
    };

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-8">
                <h2 className="text-2xl font-bold text-gray-900">{user ? t('auth.onboarding.greeting', { name: user.name.split(' ')[0] }) : t('auth.onboarding.greetingGeneric')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('auth.onboarding.intro')}</p>
            </div>

            {error && <Alert className="mb-4">{error}</Alert>}

            <div className="mb-6">
                <div className="flex items-center justify-between text-sm">
                    <span className="font-medium text-gray-700">
                        {t('auth.onboarding.progress', { done, total: steps.length })}
                    </span>
                    <span className="text-gray-500">{progress}%</span>
                </div>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-gray-200">
                    <div className="h-full rounded-full bg-[var(--accent)] transition-all" style={{ width: `${progress}%` }} />
                </div>
            </div>

            <div className="space-y-3">
                {steps.map((step, index) => (
                    <div key={step.key} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <span
                                    className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
                                        step.complete ? 'bg-emerald-100 text-emerald-700' : 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                    }`}
                                >
                                    {step.complete ? '✓' : index + 1}
                                </span>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-sm font-semibold text-gray-900">{step.title}</h3>
                                        {!step.required && <span className="text-xs text-gray-400">{t('auth.onboarding.optional')}</span>}
                                    </div>
                                    <p className="mt-0.5 text-sm text-gray-500">{step.description}</p>
                                    {step.key === 'business' && businessOpen && (
                                        <div className="mt-3 rounded-lg border border-gray-100 bg-gray-50 p-4">
                                            <div className="mb-3 space-y-3">
                                                <Input
                                                    label={t('auth.onboarding.legalName')}
                                                    name="legal_name"
                                                    value={business.legal_name}
                                                    onChange={(e) => setBusiness((b) => ({ ...b, legal_name: e.target.value }))}
                                                    error={businessErrors.legal_name}
                                                />
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label={t('auth.onboarding.industry')}
                                                        name="industry"
                                                        value={business.industry}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, industry: e.target.value }))}
                                                        error={businessErrors.industry}
                                                    />
                                                    <Input
                                                        label={t('auth.onboarding.companySize')}
                                                        name="company_size"
                                                        value={business.company_size}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, company_size: e.target.value }))}
                                                        error={businessErrors.company_size}
                                                    />
                                                </div>
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label={t('auth.onboarding.country')}
                                                        name="country"
                                                        value={business.country}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, country: e.target.value }))}
                                                        error={businessErrors.country}
                                                    />
                                                    <Input
                                                        label={t('auth.onboarding.website')}
                                                        name="website"
                                                        value={business.website}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, website: e.target.value }))}
                                                        error={businessErrors.website}
                                                    />
                                                </div>
                                            </div>
                                            <Button size="sm" onClick={saveBusiness} loading={working === 'business'}>
                                                {t('auth.onboarding.saveContinue')}
                                            </Button>
                                        </div>
                                    )}
                                    {step.key === 'hrms' && hrmsOpen && (
                                        <div className="mt-3 rounded-lg border border-gray-100 bg-gray-50 p-4">
                                            <div className="mb-3 space-y-3">
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label={t('auth.onboarding.companySize')}
                                                        name="company_size"
                                                        value={hrms.company_size}
                                                        onChange={(e) => setHrms((h) => ({ ...h, company_size: e.target.value }))}
                                                        error={hrmsErrors.company_size}
                                                    />
                                                    <Input
                                                        label={t('auth.onboarding.industry')}
                                                        name="industry"
                                                        value={hrms.industry}
                                                        onChange={(e) => setHrms((h) => ({ ...h, industry: e.target.value }))}
                                                        error={hrmsErrors.industry}
                                                    />
                                                </div>
                                                <Input
                                                    label={t('auth.onboarding.firstOffice')}
                                                    name="office_name"
                                                    value={hrms.office_name}
                                                    onChange={(e) => setHrms((h) => ({ ...h, office_name: e.target.value }))}
                                                    error={hrmsErrors.name}
                                                    placeholder={t('auth.onboarding.firstOfficePlaceholder')}
                                                />
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label={t('auth.onboarding.officeCity')}
                                                        name="office_city"
                                                        value={hrms.office_city}
                                                        onChange={(e) => setHrms((h) => ({ ...h, office_city: e.target.value }))}
                                                        error={hrmsErrors.city}
                                                    />
                                                    <Input
                                                        label={t('auth.onboarding.officeCountry')}
                                                        name="office_country"
                                                        value={hrms.office_country}
                                                        onChange={(e) => setHrms((h) => ({ ...h, office_country: e.target.value }))}
                                                        error={hrmsErrors.country}
                                                    />
                                                </div>
                                            </div>
                                            <Button size="sm" onClick={saveHrms} loading={working === 'hrms'}>
                                                {t('auth.onboarding.saveContinue')}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </div>
                            {step.complete ? (
                                <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                    {t('auth.onboarding.complete')}
                                </span>
                            ) : (
                                <div className="shrink-0">{stepContent[step.key]}</div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}