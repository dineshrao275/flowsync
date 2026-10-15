import { useEffect, useState } from 'react';
import api from '../services/api';

/**
 * Language catalog for switchers (i18n Phase 1). Served by public
 * `GET /api/locales` (completed dictionaries only); the module-level cache
 * means the login page, the Topbar, and the future marketing menu share one
 * fetch. The offline fallback offers English so the switcher never renders
 * empty when the API is unreachable.
 */
const FALLBACK = {
    default: 'en',
    locales: {
        en: { name: 'English', native: 'English', dir: 'ltr', intl: 'en-US', complete: true },
    },
};

let cached = null;

export default function useLocales() {
    const [state, setState] = useState(cached ?? { ...FALLBACK, loading: cached === null });

    useEffect(() => {
        if (cached) return undefined;

        let live = true;

        api.get('/locales')
            .then(({ data }) => {
                cached = { default: data.default ?? 'en', locales: data.locales ?? FALLBACK.locales };
                if (live) setState({ ...cached, loading: false });
            })
            .catch(() => {
                if (live) setState({ ...FALLBACK, loading: false });
            });

        return () => {
            live = false;
        };
    }, []);

    return state;
}
