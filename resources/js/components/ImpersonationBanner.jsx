import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';

export default function ImpersonationBanner() {
    const { user, stopImpersonation } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();

    if (!user?.impersonating) return null;

    async function handleStop() {
        try {
            await stopImpersonation();
            toast.success('Returned to super admin console.');
            navigate('/tenants', { replace: true });
        } catch {
            toast.error('Failed to stop impersonation.');
        }
    }

    return (
        <div className="fixed inset-x-0 top-0 z-50">
            <div className="flex items-center justify-between gap-4 bg-gradient-to-r from-amber-500 to-orange-600 px-4 py-2.5 text-sm text-white shadow-md sm:px-6">
                <div className="flex min-w-0 items-center gap-2">
                    <svg className="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                    <span className="truncate font-medium">
                        You are currently viewing the panel as <strong>{user.name}</strong>
                        <span className="ml-2 hidden text-white/80 sm:inline">
                            ({user.email})
                        </span>
                    </span>
                </div>
                <button
                    onClick={handleStop}
                    className="shrink-0 rounded-lg bg-white/20 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/30 active:scale-95"
                >
                    Stop impersonating
                </button>
            </div>
        </div>
    );
}