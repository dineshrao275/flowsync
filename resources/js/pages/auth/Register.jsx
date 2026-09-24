import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';
import usePageTitle from '../../hooks/usePageTitle';

export default function Register() {
    usePageTitle('Create your workspace');
    const { register } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [form, setForm] = useState({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        business_name: '',
        slug: '',
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    function update(field, value) {
        setForm((current) => ({ ...current, [field]: value }));
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});

        try {
            await register(form);
            toast.success('Workspace created. Let’s finish setup!');
            navigate('/onboarding', { replace: true });
        } catch (error) {
            setErrors(fieldErrors(error));
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
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <Alert>{errors.form}</Alert>
                <Input
                    label="Your name"
                    name="name"
                    required
                    placeholder="Jane Doe"
                    value={form.name}
                    onChange={(e) => update('name', e.target.value)}
                    error={errors.name}
                />
                <Input
                    label="Email address"
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    placeholder="you@example.com"
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    error={errors.email}
                />
                <Input
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
                    placeholder="At least 8 characters"
                    value={form.password}
                    onChange={(e) => update('password', e.target.value)}
                    error={errors.password}
                />
                <Input
                    label="Confirm password"
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    placeholder="Repeat your password"
                    value={form.password_confirmation}
                    onChange={(e) => update('password_confirmation', e.target.value)}
                    error={errors.password_confirmation}
                />
                <Input
                    label="Company / workspace name"
                    name="business_name"
                    required
                    placeholder="Acme Inc"
                    value={form.business_name}
                    onChange={(e) => update('business_name', e.target.value)}
                    error={errors.business_name}
                />
                <Input
                    label="Workspace URL (optional)"
                    name="slug"
                    placeholder="acme-inc"
                    value={form.slug}
                    onChange={(e) => update('slug', e.target.value)}
                    error={errors.slug}
                />
                <Button type="submit" className="w-full" loading={submitting}>
                    Create workspace
                </Button>
            </form>
        </AuthShell>
    );
}