import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';

export default function Forbidden() {
    usePageTitle('Forbidden');
    const { user } = useAuth();

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black" style={{ color: 'var(--active-menu)' }}>
                    403
                </p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">Access denied</h1>
                <p className="mt-2 text-sm text-gray-500">
                    {user ? (
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
                    className="mt-6 inline-block rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700"
                >
                    Back to dashboard
                </Link>
            </div>
        </div>
    );
}