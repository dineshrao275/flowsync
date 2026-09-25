import { Navigate, Outlet, useLocation } from 'react-router-dom';
import Spinner from './ui/Spinner';
import { useAuth } from '../context/AuthContext';

export default function ProtectedRoute({ permission, module }) {
    const { user, loading, can, hasModule } = useAuth();
    const location = useLocation();

    if (loading) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-gray-100">
                <Spinner />
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" state={{ from: location.pathname + location.search }} replace />;
    }

    if (permission && !can(permission)) {
        return <Navigate to="/403" replace />;
    }

    if (module && !hasModule(module)) {
        return <Navigate to="/403" replace />;
    }

    return <Outlet />;
}
