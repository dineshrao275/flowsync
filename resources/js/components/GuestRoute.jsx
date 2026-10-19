import { Navigate, Outlet } from 'react-router-dom';
import AppSkeleton from './ui/AppSkeleton';
import { useAuth } from '../context/AuthContext';
import { homeRouteFor } from '../utils/deepLinks';

export default function GuestRoute() {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <AppSkeleton />
        );
    }

    if (user) {
        return <Navigate to={homeRouteFor(user)} replace />;
    }

    return <Outlet />;
}
