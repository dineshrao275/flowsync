import { useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import PasswordInput from '../../components/ui/PasswordInput';
import PasswordMatchHint from '../../components/ui/PasswordMatchHint';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';
import useErrorFocus from '../../hooks/useErrorFocus';
import usePageTitle from '../../hooks/usePageTitle';

export default function ResetPassword() {
    const { t } = useTranslation();
    usePageTitle(t('auth.pageTitles.reset'));
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
    const formRef = useRef(null);
    useErrorFocus(errors, formRef);

    function update(field, value) {
        setForm((current) => ({ ...current, [field]: value }));
    }

    function clearPasswordConfirmationError() {
        setErrors((current) => {
            if (!current.password_confirmation) return current;
            const next = { ...current };
            delete next.password_confirmation;
            return next;
        });
    }

    async function handleSubmit(e) {
        e.preventDefault();
        if (form.password !== form.password_confirmation) {
            setErrors({ password_confirmation: t('ui.passwordsMismatch') });
            return;
        }
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
            title={t('auth.reset.title')}
            subtitle={t('auth.reset.subtitle')}
            footer={
                <Link to="/login" className="font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)]">
                    {t('auth.reset.backToSignIn')}
                </Link>
            }
        >
            <form ref={formRef} onSubmit={handleSubmit} className="space-y-4" noValidate>
                <Alert>{errors.form}</Alert>
                <Input
                    label={t('auth.reset.email')}
                    name="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    error={errors.email}
                />
                <PasswordInput
                    label={t('auth.reset.newPassword')}
                    name="password"
                    autoComplete="new-password"
                    required
                    placeholder={t('auth.reset.newPasswordPlaceholder')}
                    value={form.password}
                    onChange={(e) => {
                        update('password', e.target.value);
                        clearPasswordConfirmationError();
                    }}
                    error={errors.password}
                />
                <PasswordInput
                    label={t('auth.reset.confirmPassword')}
                    name="password_confirmation"
                    autoComplete="new-password"
                    required
                    placeholder={t('auth.reset.confirmPlaceholder')}
                    value={form.password_confirmation}
                    onChange={(e) => {
                        update('password_confirmation', e.target.value);
                        clearPasswordConfirmationError();
                    }}
                    error={errors.password_confirmation}
                />
                <PasswordMatchHint password={form.password} confirmation={form.password_confirmation} />
                <Button type="submit" className="w-full" loading={submitting}>
                    {t('auth.reset.resetPassword')}
                </Button>
            </form>
        </AuthShell>
    );
}