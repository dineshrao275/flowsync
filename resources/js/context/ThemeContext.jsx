import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import { applyTheme, DEFAULT_THEME, resolveMode, watchSystemScheme } from '../theme';
import { useAuth } from './AuthContext';

const ThemeContext = createContext(null);

export function ThemeProvider({ children }) {
    const { theme, setTheme } = useAuth();
    const [draft, setDraft] = useState(theme || DEFAULT_THEME);

    useEffect(() => {
        applyTheme(theme || DEFAULT_THEME);
        setDraft(theme || DEFAULT_THEME);
    }, [theme]);

    // `mode: system` follows the OS while the tab stays open.
    useEffect(() => watchSystemScheme(() => applyTheme(theme || DEFAULT_THEME)), [theme]);

    const preview = useCallback((key, value) => {
        setDraft((current) => {
            const next = { ...current, [key]: value };
            applyTheme(next);
            return next;
        });
    }, []);

    const previewFull = useCallback((nextTheme) => {
        setDraft({ ...DEFAULT_THEME, ...nextTheme });
        applyTheme({ ...DEFAULT_THEME, ...nextTheme });
    }, []);

    const save = useCallback(async () => {
        try {
            const { data } = await api.put('/theme', draft);
            setTheme(data.theme);
            applyTheme(data.theme);
            return { ok: true, message: data.message };
        } catch (error) {
            applyTheme(theme || DEFAULT_THEME);
            setDraft(theme || DEFAULT_THEME);
            return { ok: false, message: fieldErrors(error).form };
        }
    }, [draft, setTheme, theme]);

    const reset = useCallback(() => {
        previewFull(DEFAULT_THEME);
    }, [previewFull]);

    const value = { draft, setDraft, preview, previewFull, save, reset, defaults: DEFAULT_THEME, resolvedMode: resolveMode(draft?.mode) };

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
    const context = useContext(ThemeContext);
    if (!context) {
        throw new Error('useTheme must be used within a ThemeProvider');
    }
    return context;
}
