import { describe, expect, it } from 'vitest';
import { DEFAULT_THEME, contrastRatio, themeContrastIssues } from './theme';

describe('theme contrast', () => {
    it('computes WCAG ratios', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 0);
        expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
    });

    it('accepts the shipped default theme', () => {
        expect(themeContrastIssues(DEFAULT_THEME)).toEqual([]);
    });

    it('flags unreadable admin colour pairs', () => {
        const issues = themeContrastIssues({ ...DEFAULT_THEME, sidebar_text: '#1e293b', header_text: '#f1f5f9' });

        expect(issues.map((issue) => issue.id).sort()).toEqual(['header_text', 'sidebar_text']);
    });
});

describe('resolved theme (what is actually painted)', () => {
    const modes = ['light', 'dark'];

    it('passes WCAG AA for every shipped preset in both modes', async () => {
        const { THEME_PRESETS } = await import('./theme');
        const { auditTheme } = await import('./themeAudit');

        for (const preset of THEME_PRESETS) {
            for (const mode of modes) {
                const { failures } = auditTheme({ ...preset.theme, mode });

                expect(failures.map((f) => `${preset.name}/${mode}: ${f.id} ${f.ratio.toFixed(2)}<${f.min}`)).toEqual([]);
            }
        }
    });

    it('repairs hostile custom themes instead of painting unreadable text', async () => {
        const { auditTheme } = await import('./themeAudit');
        const hostile = [
            // everything the same colour
            { sidebar_bg: '#777777', sidebar_hover: '#777777', sidebar_text: '#777777', active_menu: '#777777', dashboard_bg: '#777777', header_bg: '#777777', header_text: '#777777', card_bg: '#777777', accent: '#777777' },
            // all black / all white
            { sidebar_bg: '#000000', sidebar_hover: '#000000', sidebar_text: '#000000', active_menu: '#000000', dashboard_bg: '#000000', header_bg: '#000000', header_text: '#000000', card_bg: '#000000', accent: '#000000' },
            { sidebar_bg: '#ffffff', sidebar_hover: '#ffffff', sidebar_text: '#ffffff', active_menu: '#ffffff', dashboard_bg: '#ffffff', header_bg: '#ffffff', header_text: '#ffffff', card_bg: '#ffffff', accent: '#ffffff' },
            // neon accent on white, pale text on pale sidebar
            { accent: '#ffff00', sidebar_bg: '#fafafa', sidebar_hover: '#f0f0f0', sidebar_text: '#f5f5f5', active_menu: '#fff200' },
            // dark card with a light canvas and vice versa
            { card_bg: '#10131a', dashboard_bg: '#fafafa', header_bg: '#10131a', header_text: '#10131a' },
            { card_bg: '#ffffff', dashboard_bg: '#05070b', sidebar_bg: '#ffffff', sidebar_text: '#ffffff' },
            // mid-tone surfaces, the hardest case for dark-on-light text
            { card_bg: '#8a8a8a', dashboard_bg: '#6f6f6f', accent: '#808080' },
        ];

        for (const theme of hostile) {
            for (const mode of modes) {
                const { failures } = auditTheme({ ...DEFAULT_THEME, ...theme, mode });

                expect(failures.map((f) => `${JSON.stringify(theme)} ${mode}: ${f.id} ${f.ratio.toFixed(2)}<${f.min}`)).toEqual([]);
            }
        }
    });

    it('holds for 300 random custom themes in both modes (seeded, so a failure reproduces)', async () => {
        const { auditTheme } = await import('./themeAudit');
        let seed = 20261104;
        const rand = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
        const hex = () => `#${Math.floor(rand() * 0xffffff).toString(16).padStart(6, '0')}`;
        const keys = ['sidebar_bg', 'sidebar_hover', 'active_menu', 'sidebar_text', 'dashboard_bg', 'header_bg', 'header_text', 'card_bg', 'accent'];
        const bad = [];

        for (let i = 0; i < 300; i++) {
            const theme = Object.fromEntries(keys.map((key) => [key, hex()]));
            for (const mode of modes) {
                auditTheme({ ...theme, mode }).failures.forEach((f) => bad.push(`#${i} ${mode} ${JSON.stringify(theme)}: ${f.id} ${f.ratio.toFixed(2)}<${f.min}`));
            }
        }

        expect(bad).toEqual([]);
    });

    it('leaves a healthy theme exactly as it was picked, and only nudges the failing colours', async () => {
        const { resolveTheme } = await import('./theme');

        expect(resolveTheme({ ...DEFAULT_THEME, mode: 'light' }).adjustments).toEqual([]);

        const { adjustments, vars } = resolveTheme({ ...DEFAULT_THEME, mode: 'light', sidebar_text: '#1e293b' });

        expect(adjustments.map((a) => a.key)).toEqual(['sidebar_text']);
        expect(contrastRatio(vars['--sidebar-text'], vars['--sidebar-bg'])).toBeGreaterThanOrEqual(4.5);
    });

    it('keeps the brand hue when it lightens or darkens the accent', async () => {
        const { resolveTheme } = await import('./theme');
        const hue = (hexValue) => {
            const n = [1, 3, 5].map((i) => parseInt(hexValue.slice(i, i + 2), 16) / 255);
            const [r, g, b] = n;
            const max = Math.max(r, g, b); const min = Math.min(r, g, b); const d = max - min;
            if (d === 0) return 0;
            const h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
            return (h * 60 + 360) % 360;
        };
        const { vars } = resolveTheme({ ...DEFAULT_THEME, accent: '#10b981', mode: 'light' });

        expect(Math.abs(hue(vars['--accent']) - hue('#10b981'))).toBeLessThan(8);
        expect(vars['--accent']).not.toBe('#10b981');
    });

    it('treats a dark card in light mode as the dark scheme and keeps the canvas on its side', async () => {
        const { resolveTheme } = await import('./theme');
        const result = resolveTheme({ ...DEFAULT_THEME, mode: 'light', card_bg: '#10131a', dashboard_bg: '#fafafa' });

        expect(result.dark).toBe(true);
        expect(result.surfaceDark).toBe(true);
        expect(contrastRatio(result.vars['--fg'], result.vars['--dashboard-bg'])).toBeGreaterThanOrEqual(4.5);
        expect(result.adjustments.map((a) => a.key)).toContain('dashboard_bg');
    });

    it('keeps data-coloured chips readable for ANY picked colour (mirrors the .chip-tint CSS)', () => {
        // color-mix(in srgb, chip 14%, card) behind color-mix(in srgb, chip 30%, fg) — computed the same way here.
        const mixHex = (a, b, t) => {
            const p = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
            const A = p(a); const B = p(b);
            return `#${A.map((v, i) => Math.round(v + (B[i] - v) * t).toString(16).padStart(2, '0')).join('')}`;
        };
        let seed = 7;
        const rand = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
        let worst = 99;

        for (const [fg, card] of [['#0f172a', '#ffffff'], ['#0f172a', '#f1f5f9'], ['#e8f0ff', '#171d2b']]) {
            for (let i = 0; i < 4000; i++) {
                const chip = `#${Math.floor(rand() * 0xffffff).toString(16).padStart(6, '0')}`;
                worst = Math.min(worst, contrastRatio(mixHex(fg, chip, 0.3), mixHex(card, chip, 0.14)));
            }
        }

        expect(worst).toBeGreaterThanOrEqual(4.5);
    });
});
