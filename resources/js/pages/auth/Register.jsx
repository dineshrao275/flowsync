import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import api, { fieldErrors } from '../../services/api';
import AuthShell from '../../components/ui/AuthShell';
import Spinner from '../../components/ui/Spinner';
import IntakeWizard from '../../components/tenant/IntakeWizard';
import usePageTitle from '../../hooks/usePageTitle';

// Server field names that the shared wizard shows under a different name.
const ERROR_ALIASES = { business_name: 'name', name: 'admin_name', email: 'admin_email' };

/**
 * Public sign-up. Same steps and required fields as the Super Admin tenant
 * wizard (IntakeWizard / TenantIntake); the registrant is the default user.
 * Nothing is created until the final step.
 */
export default function Register() {
    usePageTitle('Create your workspace');
    const { register } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [options, setOptions] = useState(null);
    const [values, setValues] = useState({
        name: '', slug: '', industry: '', company_size: '', country: '',
        admin_name: '', admin_email: '', plan_id: '', tms_plan_id: '', hrms_plan_id: '', start_trial: true, payment_method: '',
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [closed, setClosed] = useState(false);
    const canceledCard = new URLSearchParams(window.location.search).get('card') === 'canceled';

    useEffect(() => {
        api.get('/register/options')
            .then(({ data }) => {
                setOptions(data);
                // Start the person on the free ladder rung of each product; they can change it.
                const starter = (product) => data.plans.find((p) => p.product === product && p.price_cents === 0);
                const tms = starter('tms');
                const hrms = starter('hrms');
                if (tms || hrms) setValues((v) => ({ ...v, tms_plan_id: tms?.id ?? '', hrms_plan_id: hrms?.id ?? '' }));
                else {
                    const preferred = data.plans.find((p) => p.is_default) || data.plans[0];
                    if (preferred) setValues((v) => ({ ...v, plan_id: preferred.id }));
                }
            })
            .catch(() => setClosed(true));
    }, []);

    async function handleSubmit(password, confirmation) {
        setSubmitting(true);
        setErrors({});
        try {
            // A trial that needs a card goes through Stripe first; nothing is created until the card is saved.
            if (options.require_card_for_trial && values.start_trial) {
                const { data } = await api.post('/register/card', {
                    name: values.admin_name, email: values.admin_email, password, password_confirmation: confirmation,
                    business_name: values.name, slug: values.slug || undefined, industry: values.industry,
                    company_size: values.company_size, country: values.country, plan_id: values.plan_id || undefined,
                    tms_plan_id: values.tms_plan_id || undefined, hrms_plan_id: values.hrms_plan_id || undefined,
                });
                window.location.href = data.url;
                return;
            }
            await register({
                name: values.admin_name,
                email: values.admin_email,
                password,
                password_confirmation: confirmation,
                business_name: values.name,
                slug: values.slug || undefined,
                industry: values.industry,
                company_size: values.company_size,
                country: values.country,
                plan_id: values.plan_id || undefined,
                tms_plan_id: values.tms_plan_id || undefined,
                hrms_plan_id: values.hrms_plan_id || undefined,
                start_trial: Boolean(values.start_trial),
                payment_method: values.payment_method || undefined,
            });
            toast.success('Workspace created. Let’s finish setup!');
            navigate('/onboarding', { replace: true });
        } catch (error) {
            const raw = fieldErrors(error);
            setErrors(Object.fromEntries(Object.entries(raw).map(([k, v]) => [ERROR_ALIASES[k] || k, v])));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthShell
            title="Create your workspace"
            subtitle="Start a free trial for your team"
            footer={
                <span className="text-sm text-gray-500 dark:text-[#94A3B8]">
                    Already have an account?{' '}
                    <Link to="/login" className="font-medium text-[var(--accent)] hover:opacity-80 transition-opacity">
                        Sign in
                    </Link>
                </span>
            }
        >
            {closed ? (
                <p className="text-sm text-gray-600 dark:text-[#94A3B8]">Self-registration is currently unavailable. Please contact us for access.</p>
            ) : !options ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : (
                <>
                {canceledCard && <p className="mb-3 text-sm text-amber-700 dark:text-amber-400">Card setup was canceled — nothing was created. You can try again.</p>}
                <IntakeWizard
                    mode="register"
                    values={values}
                    onChange={setValues}
                    plans={options.plans}
                    trialDays={options.trial_days}
                    requireCard={false /* the card is collected on Stripe's page, not typed here */}
                    errors={errors}
                    saving={submitting}
                    onSubmit={handleSubmit}
                    submitLabel={options.require_card_for_trial && values.start_trial ? 'Continue to add a card' : 'Create workspace'}
                />
                </>
            )}
        </AuthShell>
    );
}
