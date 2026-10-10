import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { fieldErrors } from '../../services/api';
import AuthShell from '../../components/ui/AuthShell';
import Spinner from '../../components/ui/Spinner';
import Alert from '../../components/ui/Alert';
import usePageTitle from '../../hooks/usePageTitle';

/** Landing page after Stripe saved the card: finish the sign-up and drop the user into onboarding. */
export default function RegisterComplete() {
    usePageTitle('Finishing sign-up');
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
            setError('This sign-up link is incomplete.');
            return;
        }
        completeRegistration({ session_id, t })
            .then(() => navigate('/onboarding', { replace: true }))
            .catch((e) => setError(fieldErrors(e).form || e.response?.data?.message || 'We could not finish your sign-up.'));
    }, [params, completeRegistration, navigate]);

    return (
        <AuthShell title="Setting up your workspace" subtitle="This takes a few seconds">
            {error ? (
                <div className="space-y-3">
                    <Alert>{error}</Alert>
                    <Link to="/register" className="text-sm font-medium text-[var(--accent)] hover:opacity-80 transition-opacity">Start again</Link>
                </div>
            ) : (
                <div className="flex justify-center py-8"><Spinner /></div>
            )}
        </AuthShell>
    );
}
