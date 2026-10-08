import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';

export default function Forbidden() {
    usePageTitle('Forbidden');
    const { user } = useAuth();
    const { state } = useLocation();
    const missing = state?.permission ?? null;

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black" style={{ color: 'var(--active-menu)' }}>
                    403
                </p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">Access denied</h1>
                <p className="mt-2 text-sm text-gray-500">
                    {missing ? (
                        <>
                            This area needs the <code className="rounded bg-gray-200 px-1 py-0.5">{missing}</code> permission,
                            which your role doesn&apos;t have. An admin can grant it from Roles.
                        </>
                    ) : user?.roles?.length ? (
                        <>
                            Your account ({user.roles.map((r) => ` ${r}`)}) doesn&apos;t have permission to
                            access this area.
                        </>
                    ) : (
                        'You do not have permission to access this area.'
                    )}
                </p>
                <Link
                    to="/dashboard"
                    className="mt-6 inline-block rounded-lg bg-[var(--accent)] px-4 py-2.5 text-sm font-medium text-[var(--accent-contrast)] transition hover:bg-[var(--accent-hover)]"
                >
                    Back to dashboard
                </Link>
            </div>
        </div>
    );
}