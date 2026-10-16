import { useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import { useAuth } from '../context/AuthContext';
import { DEFAULT_LOCALE, changeLocale, intlTag, persistLocale } from '../i18n';

/**
 * The SPA leg of language selection (i18n Phase 0). `locale` is the active
 * i18next language; `setLocale` switches instantly (re-render, no reload),
 * remembers the choice for guests, and persists it server-side for signed-in
 * accounts (`PUT /api/language`). Failures keep the new language on screen
 * — the preference write is retried on the next switch, never rolled back
 * into a language the user just left.
 */
export default function useLocale() {
    const { user, refresh } = useAuth();
    const { i18n } = useTranslation();
    const locale = i18n.language || DEFAULT_LOCALE;

    const setLocale = useCallback(
        async (next) => {
            if (!next || next === i18n.language) return;

            persistLocale(next);
            await changeLocale(next);

            if (user) {
                try {
                    await api.put('/language', { locale: next });
                    await refresh();
                } catch {
                    // Stays applied locally; the next successful switch persists.
                }
            }
        },
        [i18n, user, refresh],
    );

    return { locale, setLocale, intlTag: intlTag(locale) };
}
