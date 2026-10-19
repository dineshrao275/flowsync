import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import enCommon from './locales/en/common.json';
import enAuth from './locales/en/auth.json';
import enDashboard from './locales/en/dashboard.json';
import enTms from './locales/en/tms.json';
import enHrms from './locales/en/hrms.json';
import { getAppName } from './utils/appName';
import enAdmin from './locales/en/admin.json';
import enBilling from './locales/en/billing.json';

const STORAGE_PREFIX = (typeof window !== 'undefined' && window.__FLOWSYNC_CONFIG__?.storagePrefix) || 'flowsync';

export const DEFAULT_LOCALE = 'en';
export const LOCALE_STORAGE_KEY = `${STORAGE_PREFIX}.locale`;

/**
 * Minimal `Intl` tag map (i18n Phase 0). The full catalog (tags, direction,
 * completion) is served by `GET /api/locales`; this map only covers what the
 * Phase 0 framework needs before the first server round-trip. Unknown codes
 * pass through unchanged so a future locale never breaks formatting.
 */
const INTL_TAGS = {
    en: 'en-US',
    es: 'es-ES',
};

export function intlTag(locale) {
    return INTL_TAGS[locale] ?? locale ?? 'en-US';
}

export function storedLocale() {
    if (typeof window === 'undefined') return null;

    try {
        return window.localStorage.getItem(LOCALE_STORAGE_KEY);
    } catch {
        return null;
    }
}

function persistLocale(locale) {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(LOCALE_STORAGE_KEY, locale);
    } catch {
        // Private mode — the language still applies for this page.
    }

    document.documentElement.lang = locale;
}

/**
 * Server-known preference wins over the guest guess (resolution order:
 * user → tenant → default; the tenant/default legs live server-side in
 * `SetRequestLocale`, the SPA only carries the legs it can see).
 */
export function applyServerLocale(locale) {
    if (!locale || locale === i18n.language) return;

    persistLocale(locale);
    changeLocale(locale);
}

// Non-English dictionaries are lazy: one chunk per file, fetched only when
// that language is first used. English stays statically bundled (it is the
// fallback and the test default), so an English user never downloads `es`.
const lazyBundles = import.meta.glob('./locales/*/*.json');

/** Same shape as the static `en` tree: `common.json` at the root, every other file nested under its basename. */
async function loadLocale(locale) {
    if (locale === DEFAULT_LOCALE || i18n.hasResourceBundle(locale, 'common')) return;

    const files = Object.entries(lazyBundles).filter(([path]) => path.startsWith(`./locales/${locale}/`));
    if (files.length === 0) return;

    const tree = {};
    await Promise.all(
        files.map(async ([path, load]) => {
            const name = path.split('/').pop().replace('.json', '');
            const dictionary = (await load()).default;
            if (name === 'common') Object.assign(tree, dictionary);
            else tree[name] = dictionary;
        }),
    );
    i18n.addResourceBundle(locale, 'common', tree, true, true);
}

/** Fetch the dictionary, then switch — the screen never renders a half-loaded language. */
export async function changeLocale(locale) {
    try {
        await loadLocale(locale);
    } catch {
        // Offline / chunk failure: stay on the current language rather than render keys.
        return;
    }
    await i18n.changeLanguage(locale);
}

i18n.use(initReactI18next).init({
    // One flat tree, one namespace. Shell dictionaries (`auth`, `tms`,
    // `hrms`, …) are separate JSON files merged here so translators work
    // per shell, but i18next sees a single `common` namespace: its namespace
    // separator is `:`, so dotted `t('auth.login.title')` keys resolve by
    // NESTING, not by namespace — a per-shell `ns` array would silently miss
    // every dotted lookup. Static bundling keeps this honest (no lazy
    // namespaces to drift); the whole `en` tree is smaller than one chart
    // chunk. Until a screen migrates, `t()` returns the key, so
    // partially-migrated screens degrade to visible keys, never blanks.
    resources: {
        en: { common: { ...enCommon, auth: enAuth, dashboard: enDashboard, tms: enTms, hrms: enHrms, admin: enAdmin, billing: enBilling } },
    },
    lng: DEFAULT_LOCALE,
    fallbackLng: DEFAULT_LOCALE,
    ns: ['common'],
    defaultNS: 'common',
    // `{{app}}` in any string is the configured product name (see utils/appName.js).
    interpolation: { escapeValue: false, defaultVariables: { app: getAppName() } },
    returnEmptyString: false,
    // No save backend: untranslated keys only warn in dev (missingKeyHandler
    // below) and fall back to the key via fallbackLng — never a blank.
    missingKeyHandler: (languages, namespace, key) => {
        if (import.meta.env?.DEV !== true) return;

        // eslint-disable-next-line no-console
        console.warn(`[i18n] missing key "${namespace}:${key}" for [${languages.join(', ')}]`);
    },
});

// A remembered non-English choice loads its dictionary, then switches; English
// paints immediately meanwhile (same fallback chain the missing-key path uses).
const remembered = storedLocale();
if (remembered && remembered !== DEFAULT_LOCALE) changeLocale(remembered);

if (typeof document !== 'undefined') {
    document.documentElement.lang = i18n.language;
    // Every path that changes language (guest guess, server preference,
    // switcher) flows through here, so `<html lang>` never drifts.
    i18n.on('languageChanged', (language) => {
        document.documentElement.lang = language;
    });
}

export { persistLocale };
export default i18n;
