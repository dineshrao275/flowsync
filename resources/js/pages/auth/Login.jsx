import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';

export default function Login() {
    const { login } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const location = useLocation();
    const [form, setForm] = useState({ email: '', password: '', remember: false });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    const from = location.state?.from || '/dashboard';

    function update(field, value) {
        setForm((current) => ({ ...current, [field]: value }));
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});

        try {
            const data = await login(form);
            toast.success(`Welcome back, ${data.user.name.split(' ')[0]}!`);
            navigate(from, { replace: true });
        } catch (error) {
            setErrors(fieldErrors(error));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthShell
            title="Welcome back"
            subtitle="Sign in to your admin account"
            footer={
                <span className="text-sm text-slate-500">
                    Access is managed by your tenant administrator.
                </span>
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
                    placeholder="you@example.com"
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    error={errors.email}
                />
                <Input
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    placeholder="••••••••"
                    value={form.password}
                    onChange={(e) => update('password', e.target.value)}
                    error={errors.password}
                />
                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-slate-600">
                        <input
                            type="checkbox"
                            checked={form.remember}
                            onChange={(e) => update('remember', e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-indigo-600 accent-indigo-600 transition-all duration-150 focus:ring-indigo-500"
                        />
                        Remember me
                    </label>
                    <Link
                        to="/forgot-password"
                        className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        Forgot password?
                    </Link>
                </div>
                <Button type="submit" className="w-full" loading={submitting}>
                    Sign in
                </Button>
            </form>
        </AuthShell>
    );
}