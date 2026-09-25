import { Navigate, Outlet } from 'react-router-dom';
import Spinner from './ui/Spinner';
import { useAuth } from '../context/AuthContext';
import { homeRouteFor } from '../utils/deepLinks';

export default function GuestRoute() {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-gray-100">
                <Spinner />
            </div>
        );
    }

    if (user) {
        return <Navigate to={homeRouteFor(user)} replace />;
    }

    return <Outlet />;
}
