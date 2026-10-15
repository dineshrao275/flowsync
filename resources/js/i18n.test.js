import { afterEach, describe, expect, it } from 'vitest';
import i18n, { DEFAULT_LOCALE, intlTag, storedLocale } from './i18n';

describe('i18n framework', () => {
    afterEach(async () => {
        window.localStorage.removeItem('flowsync.locale');
        await i18n.changeLanguage(DEFAULT_LOCALE);
    });

    it('falls back to the key when no dictionary ships yet', () => {
        expect(i18n.t('hrms.talent.grid.title')).toBe('hrms.talent.grid.title');
    });

    it('serves the migrated shell chrome from dictionaries', () => {
        expect(i18n.t('topbar.signOut')).toBe('Sign out');
        expect(i18n.t('topbar.searchPlaceholder')).toBe('Search…');
    });

    it('maps known locales to Intl tags and passes the rest through', () => {
        expect(intlTag('en')).toBe('en-US');
        expect(intlTag('es')).toBe('es-ES');
        expect(intlTag('fr')).toBe('fr');
    });

    it('starts from the stored guest guess', () => {
        expect(storedLocale()).toBeNull();
    });

    it('switches language without a reload', async () => {
        await i18n.changeLanguage('es');

        expect(i18n.language).toBe('es');
        expect(document.documentElement.lang).toBe('es');
    });
});
