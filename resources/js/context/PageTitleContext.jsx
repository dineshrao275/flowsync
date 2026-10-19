import { createContext, useContext, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { getAppName } from '../utils/appName';

const PageTitleContext = createContext(null);

const DEFAULT_TITLE_KEYS = {
    '/dashboard': 'admin.titles.dashboard',
    '/reports': 'admin.titles.reports',
    '/users': 'admin.titles.users',
    '/roles': 'admin.titles.roles',
    '/settings': 'admin.titles.settings',
    '/tenants': 'admin.titles.tenants',
    '/workspaces': 'admin.titles.workspaces',
    '/projects': 'admin.titles.projects',
    '/search': 'admin.titles.search',
    '/notifications': 'admin.titles.notifications',
    '/plans': 'admin.titles.plans',
    '/admin/pages': 'admin.titles.websitePages',
};

function defaultTitleFor(pathname, t) {
    const key = DEFAULT_TITLE_KEYS[pathname];

    return key ? t(key, { defaultValue: getAppName() }) : getAppName();
}

export function PageTitleProvider({ children }) {
    const { t, i18n } = useTranslation();
    const location = useLocation();
    const [prevPath, setPrevPath] = useState(location.pathname);
    const [prevLang, setPrevLang] = useState(i18n.language);
    const [title, setTitle] = useState(defaultTitleFor(location.pathname, t));

    if (prevPath !== location.pathname || prevLang !== i18n.language) {
        setPrevPath(location.pathname);
        setPrevLang(i18n.language);
        setTitle(defaultTitleFor(location.pathname, t));
    }

    const value = useMemo(() => ({ title, setTitle }), [title]);

    return <PageTitleContext.Provider value={value}>{children}</PageTitleContext.Provider>;
}

export function usePageTitleContext() {
    return useContext(PageTitleContext);
}