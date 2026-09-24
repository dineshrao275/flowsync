import { Link } from 'react-router-dom';

export default function NotFound() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-4">
            <div className="max-w-md animate-fade-in-up text-center">
                <p className="text-7xl font-black text-slate-300">404</p>
                <h1 className="mt-4 text-2xl font-bold text-gray-900">Page not found</h1>
                <p className="mt-2 text-sm text-gray-500">
                    The page you&apos;re looking for doesn&apos;t exist or has been moved.
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