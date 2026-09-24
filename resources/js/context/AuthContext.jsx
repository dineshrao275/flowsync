import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../services/api';

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

    const can = useCallback(
        (permission) => {
            if (!user) return false;
            if (user.is_super_admin && !user.impersonating) return true;
            return Boolean(user.permissions?.includes(permission));
        },
        [user],
    );

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
            refresh: loadSession,
        }),
        [user, theme, loading, login, register, logout, stopImpersonation, can, loadSession],
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