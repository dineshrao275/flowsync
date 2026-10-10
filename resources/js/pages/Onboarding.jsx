import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import Spinner from '../components/ui/Spinner';
import usePageTitle from '../hooks/usePageTitle';

export default function Onboarding() {
    usePageTitle('Set up your workspace');
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
            setError(fieldErrors(err).form || 'Unable to load onboarding.');
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
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
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
            setError(fieldErrors(err).form || `Could not complete the ${step} step.`);
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
            toast.success('Setup complete — welcome to FlowSync!');
            await refresh();
            navigate('/dashboard', { replace: true });
        } catch (err) {
            setError(fieldErrors(err).form || 'Could not finish setup.');
        } finally {
            setWorking(null);
        }
    }

    const stepContent = {
        business: (
            <button
                type="button"
                onClick={() => setBusinessOpen((open) => !open)}
                className="rounded-lg border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#161B26] px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-[#CBD5E1] transition hover:bg-gray-50 dark:hover:bg-[#1C2433]"
            >
                {businessOpen ? 'Hide form' : 'Fill business details'}
            </button>
        ),
        admin: (
            <Button size="sm" onClick={() => markStep('admin')} loading={working === 'admin'}>
                Mark as done
            </Button>
        ),
        subscription: (
            <Button size="sm" onClick={() => markStep('subscription')} loading={working === 'subscription'}>
                Use default plan
            </Button>
        ),
        configuration: (
            <Button size="sm" variant="secondary" onClick={() => markStep('configuration')} loading={working === 'configuration'}>
                I'll set it up later
            </Button>
        ),
        hrms: (
            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={() => setHrmsOpen((open) => !open)}
                    className="rounded-lg border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#161B26] px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-[#CBD5E1] transition hover:bg-gray-50 dark:hover:bg-[#1C2433]"
                >
                    {hrmsOpen ? 'Hide form' : 'Set up people'}
                </button>
                <Button size="sm" variant="secondary" onClick={() => markStep('hrms')} loading={working === 'hrms'}>
                    Later
                </Button>
            </div>
        ),
        verification: (
            <Button size="sm" variant="secondary" onClick={() => markStep('verification')} loading={working === 'verification'}>
                Confirm details
            </Button>
        ),
        completion: (
            <Button size="sm" onClick={finish} loading={working === 'completion'}>
                Finish setup
            </Button>
        ),
    };

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-8">
                <h2 className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-[#F3F4F6]">Welcome{user ? `, ${user.name.split(' ')[0]}` : ''}!</h2>
                <p className="mt-1 text-sm text-gray-500 dark:text-[#94A3B8]">A few quick steps to set up your FlowSync workspace.</p>
            </div>

            {error && <Alert className="mb-4">{error}</Alert>}

            <div className="mb-6">
                <div className="flex items-center justify-between text-sm">
                    <span className="font-medium text-gray-700 dark:text-[#CBD5E1]">
                        {done} of {steps.length} steps complete
                    </span>
                    <span className="text-gray-500 dark:text-[#94A3B8]">{progress}%</span>
                </div>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-[#2F3A4C]">
                    <div className="h-full rounded-full bg-[var(--accent)] transition-all" style={{ width: `${progress}%` }} />
                </div>
            </div>

            <div className="space-y-3">
                {steps.map((step, index) => (
                    <div key={step.key} className="rounded-xl border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#1A202C] p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <span
                                    className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
                                        step.complete ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400' : 'bg-orange-50 dark:bg-orange-950/40 text-[var(--accent)]'
                                    }`}
                                >
                                    {step.complete ? '✓' : index + 1}
                                </span>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-sm font-semibold text-gray-900 dark:text-[#F3F4F6]">{step.title}</h3>
                                        {!step.required && <span className="text-xs text-gray-400 dark:text-[#64748B]">optional</span>}
                                    </div>
                                    <p className="mt-0.5 text-sm text-gray-500 dark:text-[#94A3B8]">{step.description}</p>
                                    {step.key === 'business' && businessOpen && (
                                        <div className="mt-3 rounded-xl border border-gray-100 dark:border-[#2F3A4C] bg-gray-50/70 dark:bg-[#161B26] p-4">
                                            <div className="mb-3 space-y-3">
                                                <Input
                                                    label="Legal name"
                                                    name="legal_name"
                                                    value={business.legal_name}
                                                    onChange={(e) => setBusiness((b) => ({ ...b, legal_name: e.target.value }))}
                                                    error={businessErrors.legal_name}
                                                />
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label="Industry"
                                                        name="industry"
                                                        value={business.industry}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, industry: e.target.value }))}
                                                        error={businessErrors.industry}
                                                    />
                                                    <Input
                                                        label="Company size"
                                                        name="company_size"
                                                        value={business.company_size}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, company_size: e.target.value }))}
                                                        error={businessErrors.company_size}
                                                    />
                                                </div>
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label="Country (2-letter code)"
                                                        name="country"
                                                        value={business.country}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, country: e.target.value }))}
                                                        error={businessErrors.country}
                                                    />
                                                    <Input
                                                        label="Website"
                                                        name="website"
                                                        value={business.website}
                                                        onChange={(e) => setBusiness((b) => ({ ...b, website: e.target.value }))}
                                                        error={businessErrors.website}
                                                    />
                                                </div>
                                            </div>
                                            <Button size="sm" onClick={saveBusiness} loading={working === 'business'}>
                                                Save & continue
                                            </Button>
                                        </div>
                                    )}
                                    {step.key === 'hrms' && hrmsOpen && (
                                        <div className="mt-3 rounded-xl border border-gray-100 dark:border-[#2F3A4C] bg-gray-50/70 dark:bg-[#161B26] p-4">
                                            <div className="mb-3 space-y-3">
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label="Company size"
                                                        name="company_size"
                                                        value={hrms.company_size}
                                                        onChange={(e) => setHrms((h) => ({ ...h, company_size: e.target.value }))}
                                                        error={hrmsErrors.company_size}
                                                    />
                                                    <Input
                                                        label="Industry"
                                                        name="industry"
                                                        value={hrms.industry}
                                                        onChange={(e) => setHrms((h) => ({ ...h, industry: e.target.value }))}
                                                        error={hrmsErrors.industry}
                                                    />
                                                </div>
                                                <Input
                                                    label="First office name"
                                                    name="office_name"
                                                    value={hrms.office_name}
                                                    onChange={(e) => setHrms((h) => ({ ...h, office_name: e.target.value }))}
                                                    error={hrmsErrors.name}
                                                    placeholder="Head office"
                                                />
                                                <div className="grid grid-cols-2 gap-3">
                                                    <Input
                                                        label="Office city"
                                                        name="office_city"
                                                        value={hrms.office_city}
                                                        onChange={(e) => setHrms((h) => ({ ...h, office_city: e.target.value }))}
                                                        error={hrmsErrors.city}
                                                    />
                                                    <Input
                                                        label="Office country"
                                                        name="office_country"
                                                        value={hrms.office_country}
                                                        onChange={(e) => setHrms((h) => ({ ...h, office_country: e.target.value }))}
                                                        error={hrmsErrors.country}
                                                    />
                                                </div>
                                            </div>
                                            <Button size="sm" onClick={saveHrms} loading={working === 'hrms'}>
                                                Save & continue
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </div>
                            {step.complete ? (
                                <span className="inline-flex items-center rounded-full bg-emerald-50 dark:bg-emerald-950/40 px-2.5 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                    Complete
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