import { createContext, useContext, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';

const PageTitleContext = createContext(null);

const DEFAULT_TITLES = {
    '/dashboard': 'Dashboard',
    '/reports': 'Reports',
    '/users': 'Users',
    '/roles': 'Roles & Permissions',
    '/settings': 'Settings',
    '/tenants': 'Tenants',
    '/workspaces': 'Workspaces',
    '/projects': 'Projects',
    '/search': 'Search',
    '/notifications': 'Notifications',
    '/plans': 'Plans',
    '/admin/pages': 'Website Pages',
};

function defaultTitleFor(pathname) {
    return DEFAULT_TITLES[pathname] || 'FlowSync';
}

export function PageTitleProvider({ children }) {
    const location = useLocation();
    const [prevPath, setPrevPath] = useState(location.pathname);
    const [title, setTitle] = useState(defaultTitleFor(location.pathname));

    if (prevPath !== location.pathname) {
        setPrevPath(location.pathname);
        setTitle(defaultTitleFor(location.pathname));
    }

    const value = useMemo(() => ({ title, setTitle }), [title]);

    return <PageTitleContext.Provider value={value}>{children}</PageTitleContext.Provider>;
}

export function usePageTitleContext() {
    return useContext(PageTitleContext);
}