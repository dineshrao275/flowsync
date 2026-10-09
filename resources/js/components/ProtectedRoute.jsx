import { Navigate, Outlet, useLocation } from 'react-router-dom';
import Spinner from './ui/Spinner';
import { useAuth } from '../context/AuthContext';

// Two usages share this guard: as a layout route (`<Route element={<ProtectedRoute/>}>`
// with nested children → `<Outlet/>`) and as a wrapper around a leaf page
// (`element={<ProtectedRoute permission=…><Page/></ProtectedRoute>}`). A leaf has
// no child route, so an unconditional `<Outlet/>` rendered a blank page.
export default function ProtectedRoute({ permission, module, children }) {
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
        // The missing grant rides in location state so /403 can name it: a
        // bare "Access denied" sends an admin hunting for a route-level rule
        // that the sidebar already implied the user had.
        return <Navigate to="/403" state={{ permission }} replace />;
    }

    if (module && !hasModule(module)) {
        return <Navigate to="/module-denied" state={{ module }} replace />;
    }

    return children ?? <Outlet />;
}
