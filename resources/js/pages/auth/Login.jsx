import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';
import usePageTitle from '../../hooks/usePageTitle';
import { homeRouteFor } from '../../utils/deepLinks';

export default function Login() {
    usePageTitle('Sign in');
    const { login, verifyTwoFactor } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const location = useLocation();
    const [form, setForm] = useState({ email: '', password: '', remember: false });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [needsCode, setNeedsCode] = useState(false);
    const [code, setCode] = useState('');

    function update(field, value) {
        setForm((current) => ({ ...current, [field]: value }));
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});

        try {
            const data = needsCode ? await verifyTwoFactor(code) : await login(form);
            if (data.two_factor_required) {
                setNeedsCode(true);
                return;
            }
            toast.success(`Welcome back, ${data.user.name.split(' ')[0]}!`);
            navigate(location.state?.from || homeRouteFor(data.user), { replace: true });
        } catch (error) {
            if (error.response?.data?.code === 'two_factor_expired') {
                // The pending sign-in lapsed or burned its attempts: back to the password step.
                setNeedsCode(false);
                setCode('');
                setErrors({ form: error.response.data.message });
            } else {
                setErrors(fieldErrors(error));
            }
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
                    Access is managed by your tenant administrator.{' '}
                    <Link to="/register" className="font-medium text-[var(--accent)] hover:text-indigo-500">
                        New here? Create a workspace
                    </Link>
                </span>
            }
        >
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <Alert>{errors.form}</Alert>
                {needsCode ? (
                    <Input
                        label="Authentication code"
                        name="code"
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        placeholder="123456 or a recovery code"
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                        error={errors.code}
                    />
                ) : (<>
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
                            className="h-4 w-4 rounded border-slate-300 text-[var(--accent)] accent-[var(--accent)] transition-all duration-150 focus:ring-[var(--accent-ring)]"
                        />
                        Remember me
                    </label>
                    <Link
                        to="/forgot-password"
                        className="text-sm font-medium text-[var(--accent)] hover:text-indigo-500"
                    >
                        Forgot password?
                    </Link>
                </div>
                </>)}
                <Button type="submit" className="w-full" loading={submitting}>
                    {needsCode ? 'Verify' : 'Sign in'}
                </Button>
            </form>
        </AuthShell>
    );
}