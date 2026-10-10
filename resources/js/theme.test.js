import { describe, it, expect, beforeEach } from 'vitest';
import {
    DEFAULT_THEME,
    THEME_PRESETS,
    resolveMode,
    isDarkMode,
    accentTokens,
    dangerTokens,
    applyTheme,
} from './theme';

describe('theme system and tokens', () => {
    beforeEach(() => {
        document.documentElement.className = '';
        document.documentElement.style.cssText = '';
    });

    it('defines valid default theme tokens', () => {
        expect(DEFAULT_THEME.sidebar_bg).toBeDefined();
        expect(DEFAULT_THEME.accent).toBeDefined();
        expect(DEFAULT_THEME.mode).toBe('system');
    });

    it('resolves mode correctly for explicit and system settings', () => {
        expect(resolveMode('light')).toBe('light');
        expect(resolveMode('dark')).toBe('dark');
        expect(['light', 'dark']).toContain(resolveMode('system'));
    });

    it('identifies dark mode correctly', () => {
        expect(isDarkMode({ mode: 'dark' })).toBe(true);
        expect(isDarkMode({ mode: 'light' })).toBe(false);
    });

    it('generates high contrast accent tokens with luminance calculations', () => {
        // Dark/mid accent should have white text
        const darkAccent = accentTokens('#C2410C', false);
        expect(darkAccent['--accent']).toBe('#C2410C');
        expect(darkAccent['--accent-contrast']).toBe('#ffffff');

        // Bright accent should have dark text for contrast
        const brightAccent = accentTokens('#FACC15', false);
        expect(brightAccent['--accent-contrast']).toBe('#0f172a');

        // Dark mode canvas mix
        const darkModeTokens = accentTokens('#6366F1', true);
        expect(darkModeTokens['--accent']).toBe('#6366F1');
        expect(darkModeTokens['--accent-hover']).toBeDefined();
    });

    it('generates danger tokens for light and dark modes', () => {
        const lightDanger = dangerTokens(false);
        expect(lightDanger['--danger']).toBe('#dc2626');
        expect(lightDanger['--danger-contrast']).toBe('#ffffff');

        const darkDanger = dangerTokens(true);
        expect(darkDanger['--danger']).toBe('#ef4444');
        expect(darkDanger['--danger-contrast']).toBe('#ffffff');
    });

    it('contains all 4 approved preset palettes with valid surface colors', () => {
        expect(THEME_PRESETS).toHaveLength(4);
        const names = THEME_PRESETS.map((p) => p.name);
        expect(names).toContain('Indigo Slate');
        expect(names).toContain('Emerald Dark');
        expect(names).toContain('Rose Night');
        expect(names).toContain('Blue Steel');

        THEME_PRESETS.forEach((preset) => {
            expect(preset.theme.sidebar_bg).toMatch(/^#[0-9a-fA-F]{6}$/);
            expect(preset.theme.accent).toMatch(/^#[0-9a-fA-F]{6}$/);
        });
    });

    it('applies theme to DOM root and updates CSS custom properties', () => {
        applyTheme({ mode: 'dark', accent: '#F97316' });

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
        expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#F97316');
        expect(document.documentElement.style.getPropertyValue('--sidebar-bg')).toBe('#182030');

        applyTheme({ mode: 'light', accent: '#6366F1' });
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('light');
        expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#6366F1');
    });
});
