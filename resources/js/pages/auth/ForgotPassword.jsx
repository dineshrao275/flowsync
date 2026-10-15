import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Trans, useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';
import useErrorFocus from '../../hooks/useErrorFocus';
import usePageTitle from '../../hooks/usePageTitle';

export default function ForgotPassword() {
    const { t } = useTranslation();
    usePageTitle(t('auth.pageTitles.forgot'));
    const [email, setEmail] = useState('');
    const [errors, setErrors] = useState({});
    const [sent, setSent] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const formRef = useRef(null);
    useErrorFocus(errors, formRef);

    async function handleSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});

        try {
            await api.post('/auth/forgot-password', { email });
            setSent(true);
        } catch (error) {
            setErrors(fieldErrors(error));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthShell
            title={t('auth.forgot.title')}
            subtitle={t('auth.forgot.subtitle')}
            footer={
                <Link to="/login" className="font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)]">
                    {t('auth.forgot.backToSignIn')}
                </Link>
            }
        >
            {sent ? (
                <div className="space-y-4">
                    <Alert type="success">
                        <Trans i18nKey="auth.forgot.sent" values={{ email }} components={{ strong: <strong /> }} />
                    </Alert>
                    <Link to="/login">
                        <Button variant="secondary" className="w-full">
                            {t('auth.forgot.returnToSignIn')}
                        </Button>
                    </Link>
                </div>
            ) : (
                <form ref={formRef} onSubmit={handleSubmit} className="space-y-4" noValidate>
                    <Alert>{errors.form}</Alert>
                    <Input
                        label={t('auth.forgot.email')}
                        name="email"
                        type="email"
                        autoComplete="email"
                        required
                        placeholder={t('auth.forgot.emailPlaceholder')}
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        error={errors.email}
                    />
                    <Button type="submit" className="w-full" loading={submitting}>
                        {t('auth.forgot.sendLink')}
                    </Button>
                </form>
            )}
        </AuthShell>
    );
}