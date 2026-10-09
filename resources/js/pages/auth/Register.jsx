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
        admin_name: '', admin_email: '', plan_id: '', start_trial: true, payment_method: '',
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [closed, setClosed] = useState(false);

    useEffect(() => {
        api.get('/register/options')
            .then(({ data }) => {
                setOptions(data);
                const preferred = data.plans.find((p) => p.is_default) || data.plans[0];
                if (preferred) setValues((v) => ({ ...v, plan_id: preferred.id }));
            })
            .catch(() => setClosed(true));
    }, []);

    async function handleSubmit(password, confirmation) {
        setSubmitting(true);
        setErrors({});
        try {
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
                <span className="text-sm text-slate-500">
                    Already have an account?{' '}
                    <Link to="/login" className="font-medium text-indigo-600 hover:text-indigo-500">
                        Sign in
                    </Link>
                </span>
            }
        >
            {closed ? (
                <p className="text-sm text-slate-600">Self-registration is currently unavailable. Please contact us for access.</p>
            ) : !options ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : (
                <IntakeWizard
                    mode="register"
                    values={values}
                    onChange={setValues}
                    plans={options.plans}
                    trialDays={options.trial_days}
                    requireCard={options.require_card_for_trial}
                    errors={errors}
                    saving={submitting}
                    onSubmit={handleSubmit}
                    submitLabel="Create workspace"
                />
            )}
        </AuthShell>
    );
}
