import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';

export default function ModuleDenied() {
    usePageTitle('Not in your plan');
    const { user, can } = useAuth();

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black" style={{ color: 'var(--active-menu)' }}>
                    403
                </p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">Not included in your plan</h1>
                <p className="mt-2 text-sm text-gray-500">
                    This feature is not included in your current plan.
                    {user && !can('billing.view') && (
                        <> You can still use it once an account administrator upgrades your subscription.</>
                    )}
                </p>
                <div className="mt-6 flex items-center justify-center gap-3">
                    <Link
                        to="/dashboard"
                        className="inline-block rounded-lg bg-[var(--accent)] px-4 py-2.5 text-sm font-medium text-[var(--accent-contrast)] transition hover:bg-[var(--accent-hover)]"
                    >
                        Back to dashboard
                    </Link>
                    {can('billing.view') && (
                        <Link
                            to="/subscription"
                            className="inline-block rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                        >
                            View plans
                        </Link>
                    )}
                </div>
            </div>
        </div>
    );
}