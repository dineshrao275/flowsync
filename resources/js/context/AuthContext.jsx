import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../services/api';

const MODULE_PREFIX = 'module:';

// Extracts the module name from a `module:<name>` capability string, else null.
const moduleName = (capability) =>
    typeof capability === 'string' && capability.startsWith(MODULE_PREFIX)
        ? capability.slice(MODULE_PREFIX.length)
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
            if (moduleName(capability)) {
                return unrestricted() || Boolean(user.modules?.includes(moduleName(capability)));
            }
            return unrestricted() || Boolean(user.permissions?.includes(capability));
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
            logout,
            stopImpersonation,
            can,
            hasModule,
            check,
            refresh: loadSession,
        }),
        [user, theme, loading, login, register, logout, stopImpersonation, can, hasModule, check, loadSession],
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