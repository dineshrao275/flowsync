import { afterEach, describe, expect, it } from 'vitest';
import i18n, { DEFAULT_LOCALE, changeLocale, intlTag, storedLocale } from './i18n';

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

    it('lazy-loads the Spanish dictionary and falls back to English for gaps', async () => {
        expect(i18n.hasResourceBundle('es', 'common')).toBe(false);

        await changeLocale('es');

        expect(i18n.language).toBe('es');
        expect(i18n.t('topbar.signOut')).toBe('Cerrar sesión');
        expect(i18n.t('auth.login.title')).toBe('Te damos la bienvenida de nuevo');
        expect(i18n.t('tms.board.dropHere')).toBe('Suelta las tareas aquí');
        expect(i18n.t('hrms.overview.heading')).toBe('Recursos Humanos');
        expect(i18n.t('admin.common.save')).toBe('Guardar');
        // Interpolation survives translation.
        expect(i18n.t('auth.login.welcomeBack', { name: 'Ana' })).toBe('¡Te damos la bienvenida de nuevo, Ana!');
    });

    it('formats relative time with the active language, not hand-built English', async () => {
        const { formatRelativeTime } = await import('./utils/format');
        const threeHoursAgo = new Date(Date.now() - 3 * 3600 * 1000).toISOString();

        expect(formatRelativeTime(threeHoursAgo)).toMatch(/3 hours ago/);

        await changeLocale('es');

        expect(formatRelativeTime(threeHoursAgo)).toMatch(/hace 3 horas/);
        expect(formatRelativeTime(new Date().toISOString())).toBe('justo ahora');
    });
});
