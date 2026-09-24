import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';
import usePageTitle from '../../hooks/usePageTitle';

export default function ResetPassword() {
    usePageTitle('Reset password');
    const [searchParams] = useSearchParams();
    const emailParam = searchParams.get('email') || '';
    const token = searchParams.get('token') || '';
    const navigate = useNavigate();

    const [form, setForm] = useState({
        email: emailParam,
        token,
        password: '',
        password_confirmation: '',
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
            await api.post('/auth/reset-password', form);
            navigate('/login', { replace: true });
        } catch (error) {
            setErrors(fieldErrors(error));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthShell
            title="Choose a new password"
            subtitle="Enter your new password below"
            footer={
                <Link to="/login" className="font-medium text-indigo-600 hover:text-indigo-500">
                    Back to sign in
                </Link>
            }
        >
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <Alert>{errors.form}</Alert>
                <Input
                    label="Email address"
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    error={errors.email}
                />
                <Input
                    label="New password"
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
                    placeholder="Repeat password"
                    value={form.password_confirmation}
                    onChange={(e) => update('password_confirmation', e.target.value)}
                    error={errors.password_confirmation}
                />
                <Button type="submit" className="w-full" loading={submitting}>
                    Reset password
                </Button>
            </form>
        </AuthShell>
    );
}