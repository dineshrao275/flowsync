import { useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import PasswordInput from '../../components/ui/PasswordInput';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';
import useErrorFocus from '../../hooks/useErrorFocus';
import usePageTitle from '../../hooks/usePageTitle';
import { homeRouteFor } from '../../utils/deepLinks';

export default function Login() {
    const { t } = useTranslation();
    usePageTitle(t('auth.pageTitles.signIn'));
    const { login, verifyTwoFactor } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const location = useLocation();
    const [form, setForm] = useState({ email: '', password: '', remember: false });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [needsCode, setNeedsCode] = useState(false);
    const [code, setCode] = useState('');
    const formRef = useRef(null);
    const [ssoSlug, setSsoSlug] = useState('');
    const ssoError = new URLSearchParams(location.search).get('sso_error');
    useErrorFocus(errors, formRef);

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
            toast.success(t('auth.login.welcomeBack', { name: data.user.name.split(' ')[0] }));
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
            title={t('auth.login.title')}
            subtitle={t('auth.login.subtitle')}
            footer={
                <span className="text-sm text-slate-500">
                    {t('auth.login.managedBy')}{' '}
                    <Link to="/register" className="font-medium text-[var(--accent)] hover:text-[var(--accent)]">
                        {t('auth.login.newHere')}
                    </Link>
                </span>
            }
        >
            <form ref={formRef} onSubmit={handleSubmit} className="space-y-4" noValidate>
                <Alert>{errors.form}</Alert>
                {ssoError && <Alert>{t(`auth.login.sso.errors.${ssoError}`, { defaultValue: t('auth.login.sso.errors.sso_failed') })}</Alert>}
                {needsCode ? (
                    <Input
                        label={t('auth.login.code')}
                        name="code"
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        placeholder={t('auth.login.codePlaceholder')}
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                        error={errors.code}
                    />
                ) : (<>
                <Input
                    label={t('auth.login.email')}
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    placeholder={t('auth.login.emailPlaceholder')}
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    error={errors.email}
                />
                <PasswordInput
                    label={t('auth.login.password')}
                    name="password"
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
                        {t('auth.login.rememberMe')}
                    </label>
                    <Link
                        to="/forgot-password"
                        className="text-sm font-medium text-[var(--accent)] hover:text-[var(--accent)]"
                    >
                        {t('auth.login.forgotPassword')}
                    </Link>
                </div>
                </>)}
                <Button type="submit" className="w-full" loading={submitting}>
                    {needsCode ? t('auth.login.verify') : t('auth.login.signIn')}
                </Button>
            </form>
            {!needsCode && (
                <form
                    className="mt-5 flex items-end gap-2 border-t border-slate-200 pt-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (ssoSlug.trim()) window.location.assign(`/api/sso/${encodeURIComponent(ssoSlug.trim().toLowerCase())}/start`);
                    }}
                >
                    <div className="flex-1"><Input label={t('auth.login.sso.workspace')} name="workspace" placeholder="acme" value={ssoSlug} onChange={(e) => setSsoSlug(e.target.value)} /></div>
                    <Button type="submit" variant="secondary" disabled={!ssoSlug.trim()}>{t('auth.login.sso.button')}</Button>
                </form>
            )}
        </AuthShell>
    );
}