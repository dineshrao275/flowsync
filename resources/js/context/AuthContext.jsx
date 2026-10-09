import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../services/api';

const MODULE_PREFIX = 'module:';
const PERMISSION_PREFIX = 'permission:';

// Extracts the module name from a `module:<name>` capability string, else null.
const moduleName = (capability) =>
    typeof capability === 'string' && capability.startsWith(MODULE_PREFIX)
        ? capability.slice(MODULE_PREFIX.length)
        : null;

// Extracts the slug from a `permission:<slug>` capability string, else null.
//
// `user.permissions` from `/api/auth/me` carries bare slugs (`hrms.org.manage`),
// but every HRMS call site writes the prefixed form (`can('permission:…')`) —
// without this strip, all of them are false for every tenant user and the
// manage buttons, directory pickers and nav items they gate never render.
// Bare slugs still match, so the older `can('workspaces.manage')` calls keep
// working untouched.
const permissionName = (capability) =>
    typeof capability === 'string' && capability.startsWith(PERMISSION_PREFIX)
        ? capability.slice(PERMISSION_PREFIX.length)
        : null;

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [theme, setTheme] = useState(null);
    const [loading, setLoading] = useState(true);

    const loadSession = useCallback(async () => {
        try {
            const { data } = await api.get('/auth/me');
            setUser(data.user);
            setTheme(data.theme);
        } catch {
            setUser(null);
            setTheme(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadSession();
    }, [loadSession]);

    const login = useCallback(async (credentials) => {
        const { data } = await api.post('/auth/login', credentials);
        setUser(data.user);
        setTheme(data.theme);
        return data;
    }, []);

    const register = useCallback(async (credentials) => {
        const { data } = await api.post('/register', credentials);
        setUser(data.user);
        setTheme(data.theme);
        return data;
    }, []);

    // Second half of a card-backed sign-up: the server verified the saved card and provisioned the tenant.
    const completeRegistration = useCallback(async (params) => {
        const { data } = await api.post('/register/complete', params);
        setUser(data.user);
        setTheme(data.theme);
        return data;
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            setUser(null);
            setTheme(null);
        }
    }, []);

    const stopImpersonation = useCallback(async () => {
        const { data } = await api.post('/impersonate/stop');
        setUser(data.user);
        setTheme(data.theme);
        return data;
    }, []);

    const unrestricted = useCallback(() => Boolean(user?.is_super_admin && !user.impersonating), [user]);

    const hasAccess = useCallback(
        (capability) => {
            if (!user) return false;
            if (unrestricted()) return true;
            if (moduleName(capability)) {
                return Boolean(user.modules?.includes(moduleName(capability)));
            }
            const permission = permissionName(capability) ?? capability;
            return Boolean(user.permissions?.includes(permission));
        },
        [user, unrestricted],
    );

    const can = useCallback((permission) => hasAccess(permission), [hasAccess]);

    const hasModule = useCallback((module) => hasAccess(`module:${module}`), [hasAccess]);

    // Single entry that understands "permission" and "module:<name>" scopes.
    const check = useCallback((capability) => hasAccess(capability), [hasAccess]);

    const value = useMemo(
        () => ({
            user,
            theme,
            setTheme,
            loading,
            login,
            register,
            completeRegistration,
            logout,
            stopImpersonation,
            can,
            hasModule,
            check,
            refresh: loadSession,
        }),
        [user, theme, loading, login, register, completeRegistration, logout, stopImpersonation, can, hasModule, check, loadSession],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
}