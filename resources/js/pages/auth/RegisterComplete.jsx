import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../context/AuthContext';
import { fieldErrors } from '../../services/api';
import AuthShell from '../../components/ui/AuthShell';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import usePageTitle from '../../hooks/usePageTitle';

/** Landing page after Stripe saved the card: finish the sign-up and drop the user into onboarding. */
export default function RegisterComplete() {
    const { t } = useTranslation();
    usePageTitle(t('auth.pageTitles.finishing'));
    const [params] = useSearchParams();
    const { completeRegistration } = useAuth();
    const navigate = useNavigate();
    const [error, setError] = useState(null);
    const started = useRef(false);

    useEffect(() => {
        if (started.current) return;
        started.current = true;
        const session_id = params.get('session_id');
        const t = params.get('t');
        if (!session_id || !t) {
            setError(t('auth.complete.incompleteLink'));
            return;
        }
        completeRegistration({ session_id, t })
            .then(() => navigate('/onboarding', { replace: true }))
            .catch((e) => setError(fieldErrors(e).form || e.response?.data?.message || t('auth.complete.failed')));
    }, [params, completeRegistration, navigate]);

    return (
        <AuthShell title={t('auth.complete.title')} subtitle={t('auth.complete.subtitle')}>
            {error ? (
                <div className="space-y-3">
                    <Alert>{error}</Alert>
                    <Link to="/register" className="text-sm font-medium text-[var(--accent-soft-text)]">{t('auth.complete.startAgain')}</Link>
                </div>
            ) : (
                <ContentSkeleton size="md" />
            )}
        </AuthShell>
    );
}
