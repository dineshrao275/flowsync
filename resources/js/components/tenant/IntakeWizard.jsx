import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Alert from '../ui/Alert';
import Card from '../ui/Card';
import useErrorFocus from '../../hooks/useErrorFocus';
import { formatPrice } from '../../utils/format';

/**
 * The tenant intake steps, shared by the Super Admin create/edit page and public
 * registration so both ask for exactly the same required data. Field names are
 * the server's (`TenantIntake`); the host page decides how a step is persisted.
 *
 * The default user's password is only ever asked on the last step and is never
 * saved with a draft.
 */
export const REQUIRED = {
    business: ['name', 'slug', 'industry', 'company_size', 'country'],
    admin: ['admin_name', 'admin_email'],
    plan: [],
};

const ADMIN_BUSINESS_REQUIRED = ['billing_email', 'contact_name', 'contact_email'];

const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1000+'];

function slugify(value) {
    return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

export default function IntakeWizard({
    mode = 'admin', // 'admin' = Super Admin wizard, 'register' = public sign-up
    values,
    onChange,
    plans = [],
    trialDays = 14,
    requireCard = false,
    errors = {},
    saving = false,
    onSaveStep, // async (stepKey) => boolean — persist the draft; return false to stay
    onSubmit, // async (password, confirmation) => void
    submitLabel: submitLabelProp,
    lockSlug = false,
}) {
    const steps = useMemo(() => ['business', 'admin', 'plan', 'review'], []);
    const [index, setIndex] = useState(0);
    const { t } = useTranslation();
    const submitLabel = submitLabelProp ?? t('auth.wizard.createTenant');
    const [slugTouched, setSlugTouched] = useState(Boolean(values.slug));
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [localErrors, setLocalErrors] = useState({});
    const step = steps[index];
    const isRegister = mode === 'register';
    const err = (key) => localErrors[key] || errors[key];
    const wizardRef = useRef(null);
    // Server errors arrive after submit; local ones on step validation — the
    // hook answers both, since it watches the merged identity each render.
    useErrorFocus(useMemo(() => {
        const merged = {};
        for (const key of Object.keys(localErrors)) merged[key] = localErrors[key];
        for (const key of Object.keys(errors)) merged[key] = errors[key];
        return merged;
    }, [localErrors, errors]), wizardRef);

    const set = (key, value) => onChange({ ...values, [key]: value });

    function requiredFor(stepKey) {
        const base = REQUIRED[stepKey] || [];
        return stepKey === 'business' && !isRegister ? [...base, ...ADMIN_BUSINESS_REQUIRED] : base;
    }

    function validate(stepKey) {
        const missing = {};
        requiredFor(stepKey).forEach((key) => {
            if (values[key] === undefined || values[key] === null || String(values[key]).trim() === '') {
                missing[key] = t('auth.wizard.fieldRequired');
            }
        });
        if (stepKey === 'plan' && !values.plan_id && !values.tms_plan_id && !values.hrms_plan_id) {
            missing.plan_id = t('auth.wizard.choosePlan');
        }
        if (stepKey === 'plan' && values.start_trial && requireCard && !values.payment_method) {
            missing.payment_method = t('auth.wizard.paymentRequired');
        }
        setLocalErrors(missing);
        return Object.keys(missing).length === 0;
    }

    async function next() {
        if (!validate(step)) return;
        if (onSaveStep && !(await onSaveStep(step))) return;
        setIndex((i) => Math.min(i + 1, steps.length - 1));
    }

    async function submit(e) {
        e.preventDefault();
        const problems = {};
        if (password.length < 8) problems.admin_password = t('auth.wizard.passwordLength');
        if (password !== confirmation) problems.admin_password_confirmation = t('auth.wizard.passwordMismatch');
        setLocalErrors(problems);
        if (Object.keys(problems).length) return;
        await onSubmit(password, confirmation);
    }

    const chosen = [values.plan_id, values.tms_plan_id, values.hrms_plan_id].filter(Boolean).map((id) => plans.find((p) => String(p.id) === String(id))?.name).filter(Boolean);
    const titles = {
        business: t('auth.wizard.steps.business'),
        admin: isRegister ? t('auth.wizard.steps.admin') : t('auth.wizard.steps.adminDefault'),
        plan: t('auth.wizard.steps.plan'),
        review: t('auth.wizard.steps.review'),
    };

    return (
        <div ref={wizardRef} className="space-y-6">
            <ol className="flex flex-wrap items-center gap-2 text-sm">
                {steps.map((key, i) => (
                    <li
                        key={key}
                        className={`flex items-center gap-2 rounded-full px-3 py-1 ${
                            i === index ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : i < index ? 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)]' : 'bg-gray-100 text-gray-500'
                        }`}
                    >
                        <span className="font-semibold">{i + 1}</span>
                        {titles[key]}
                    </li>
                ))}
            </ol>

            <Alert>{errors.form}</Alert>

            {step === 'business' && (
                <Card title={t('auth.wizard.businessTitle')} subtitle={t('auth.wizard.businessSubtitle')}>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Input
                            label={t('auth.wizard.companyName')} name="name" required value={values.name || ''}
                            onChange={(e) => {
                                const name = e.target.value;
                                onChange({ ...values, name, ...(slugTouched || lockSlug ? {} : { slug: slugify(name) }) });
                            }}
                            error={err('name')}
                        />
                        <Input
                            label={t('auth.wizard.slug')} name="slug" required value={values.slug || ''} disabled={lockSlug}
                            onChange={(e) => { setSlugTouched(true); set('slug', slugify(e.target.value)); }}
                            error={err('slug')}
                        />
                        <Input label={t('auth.wizard.industry')} name="industry" required value={values.industry || ''} onChange={(e) => set('industry', e.target.value)} error={err('industry')} />
                        <label className="block text-sm font-medium text-gray-700">
                            {t('auth.wizard.companySize')}
                            <select
                                name="company_size" value={values.company_size || ''} onChange={(e) => set('company_size', e.target.value)}
                                className="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[var(--accent)]"
                            >
                                <option value="">{t('auth.wizard.companySizePlaceholder')}</option>
                                {COMPANY_SIZES.map((s) => <option key={s} value={s}>{t('auth.wizard.companySizePeople', { size: s })}</option>)}
                            </select>
                            {err('company_size') && <span className="mt-1 block text-sm text-red-600">{err('company_size')}</span>}
                        </label>
                        <Input label={t('auth.wizard.country')} name="country" required maxLength={2} placeholder="US" value={values.country || ''} onChange={(e) => set('country', e.target.value.toUpperCase())} error={err('country')} />
                        {!isRegister && (
                            <>
                                <Input label={t('auth.wizard.billingEmail')} name="billing_email" type="email" required value={values.billing_email || ''} onChange={(e) => set('billing_email', e.target.value)} error={err('billing_email')} />
                                <Input label={t('auth.wizard.contactName')} name="contact_name" required value={values.contact_name || ''} onChange={(e) => set('contact_name', e.target.value)} error={err('contact_name')} />
                                <Input label={t('auth.wizard.contactEmail')} name="contact_email" type="email" required value={values.contact_email || ''} onChange={(e) => set('contact_email', e.target.value)} error={err('contact_email')} />
                            </>
                        )}
                    </div>
                </Card>
            )}

            {step === 'admin' && (
                <Card
                    title={isRegister ? t('auth.wizard.adminTitle') : t('auth.wizard.adminTitleDefault')}
                    subtitle={isRegister
                        ? t('auth.wizard.adminSubtitle')
                        : t('auth.wizard.adminSubtitleDefault')}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Input label={t('auth.wizard.fullName')} name="admin_name" required value={values.admin_name || ''} onChange={(e) => set('admin_name', e.target.value)} error={err('admin_name')} />
                        <Input label={t('auth.wizard.emailLogin')} name="admin_email" type="email" required value={values.admin_email || ''} onChange={(e) => set('admin_email', e.target.value)} error={err('admin_email')} />
                    </div>
                </Card>
            )}

            {step === 'plan' && (
                <Card title={t('auth.wizard.planTitle')} subtitle={t('auth.wizard.planSubtitle')}>
                    {[['tms', t('auth.wizard.tms'), 'tms_plan_id'], ['hrms', t('auth.wizard.hrms'), 'hrms_plan_id']].map(([product, title, field]) => {
                        const choices = plans.filter((p) => p.product === product);
                        if (choices.length === 0) return null;
                        return (
                            <div key={product} className="mb-4">
                                <p className="mb-2 text-sm font-semibold text-gray-800">{title}</p>
                                <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                                    <PlanChoice selected={!values[field]} onClick={() => onChange({ ...values, [field]: '' })} title={t('auth.wizard.none')} body={t('auth.wizard.notNeeded')} />
                                    {choices.map((p) => (
                                        <PlanChoice
                                            key={p.id}
                                            selected={String(values[field]) === String(p.id)}
                                            onClick={() => onChange({ ...values, [field]: p.id, plan_id: '' })}
                                            title={p.name}
                                            body={formatPrice(p)}
                                        />
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                    {plans.some((p) => !p.product || p.product === 'suite') && (
                        <details className="mb-2 text-sm text-gray-600" open={Boolean(values.plan_id)}>
                            <summary className="cursor-pointer">{t('auth.wizard.legacyBundle')}</summary>
                            <div className="mt-2 grid grid-cols-1 gap-3 md:grid-cols-3">
                                {plans.filter((p) => !p.product || p.product === 'suite').map((p) => (
                                    <PlanChoice
                                        key={p.id}
                                        selected={String(values.plan_id) === String(p.id)}
                                        onClick={() => onChange({ ...values, plan_id: p.id, tms_plan_id: '', hrms_plan_id: '' })}
                                        title={p.name}
                                        body={formatPrice(p)}
                                    />
                                ))}
                            </div>
                        </details>
                    )}
                    {err('plan_id') && <p className="mt-2 text-sm text-red-600">{err('plan_id')}</p>}
                    <label className="mt-4 flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" className="mt-1" checked={Boolean(values.start_trial)} onChange={(e) => set('start_trial', e.target.checked)} />
                        <span>{t('auth.wizard.startTrial', { days: trialDays })}</span>
                    </label>
                    {values.start_trial && requireCard && (
                        <div className="mt-3">
                            <Input
                                label={t('auth.wizard.paymentMethod')} name="payment_method" value={values.payment_method || ''}
                                placeholder={t('auth.wizard.paymentPlaceholder')} onChange={(e) => set('payment_method', e.target.value)}
                                error={err('payment_method')}
                            />
                            <p className="mt-1 text-xs text-gray-500">{t('auth.wizard.cardRequiredNote')}</p>
                        </div>
                    )}
                </Card>
            )}

            {step === 'review' && (
                <form onSubmit={submit} noValidate>
                    <Card title={t('auth.wizard.reviewTitle')} subtitle={t('auth.wizard.reviewSubtitle')}>
                        <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <Row k={t('auth.wizard.rowCompany')} v={values.name} />
                            <Row k={t('auth.wizard.rowUrl')} v={values.slug} />
                            <Row k={t('auth.wizard.rowIndustry')} v={values.industry} />
                            <Row k={t('auth.wizard.rowSizeCountry')} v={`${values.company_size || '—'} · ${values.country || '—'}`} />
                            <Row k={isRegister ? t('auth.wizard.steps.admin') : t('auth.wizard.steps.adminDefault')} v={`${values.admin_name} <${values.admin_email}>`} />
                            <Row k={t('auth.wizard.rowPlans')} v={chosen.join(' + ') || '—'} />
                            <Row k={t('auth.wizard.rowTrial')} v={values.start_trial ? t('auth.wizard.trialDays', { days: trialDays }) : t('auth.wizard.noTrial')} />
                        </dl>
                        <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input label={isRegister ? t('auth.wizard.password') : t('auth.wizard.passwordDefault')} name="admin_password" type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} error={err('admin_password')} />
                            <Input label={t('auth.wizard.confirmPassword')} name="admin_password_confirmation" type="password" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} error={err('admin_password_confirmation')} />
                        </div>
                        <div className="mt-5 flex gap-2">
                            <Button type="button" variant="secondary" onClick={() => setIndex(index - 1)}>{t('auth.wizard.back')}</Button>
                            <Button type="submit" loading={saving}>{submitLabel}</Button>
                        </div>
                    </Card>
                </form>
            )}

            {step !== 'review' && (
                <div className="flex gap-2">
                    {index > 0 && <Button type="button" variant="secondary" onClick={() => setIndex(index - 1)}>{t('auth.wizard.back')}</Button>}
                    <Button type="button" onClick={next} loading={saving}>{t('auth.wizard.next')}</Button>
                </div>
            )}
        </div>
    );
}

function Row({ k, v }) {
    return (
        <div className="flex justify-between gap-3 border-b border-gray-100 py-1">
            <dt className="text-gray-500">{k}</dt>
            <dd className="font-medium text-gray-800">{v || '—'}</dd>
        </div>
    );
}

function PlanChoice({ selected, onClick, title, body }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-xl border p-3 text-left transition ${selected ? 'border-[var(--accent)] ring-2 ring-[var(--accent-ring)]' : 'border-gray-200 hover:border-gray-300'}`}
        >
            <p className="font-semibold text-gray-900">{title}</p>
            <p className="text-sm text-gray-500">{body}</p>
        </button>
    );
}
