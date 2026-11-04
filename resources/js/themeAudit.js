import { contrastRatio, resolveTheme } from './theme.js';

/**
 * Every foreground/background pair the UI paints, evaluated on a RESOLVED theme (what is actually applied),
 * with the WCAG 2.x minimum it must meet: 4.5:1 for text, 3:1 for UI boundaries. Used by the unit test (all
 * presets x both modes x adversarial custom themes) and by `scripts/theme-contrast-audit.mjs`.
 */
export function auditTheme(theme) {
    const { vars, dark } = resolveTheme(theme);
    const v = (name) => vars[name.startsWith('--') ? name : `--${name}`];
    const rows = [];
    const check = (id, fg, bgs, min) => {
        [].concat(bgs).forEach((bg) => {
            const f = fg.startsWith('#') ? fg : v(fg);
            const b = bg.startsWith('#') ? bg : v(bg);
            rows.push({ id: `${id} on ${bg.startsWith('#') ? bg : bg}`, fg: f, bg: b, ratio: contrastRatio(f, b), min });
        });
    };
    const surfaces = ['card-bg', 'dashboard-bg', 'surface-sunken'];

    check('sidebar-text', 'sidebar-text', ['sidebar-bg', 'sidebar-hover'], 4.5);
    check('sidebar-text-muted', 'sidebar-text-muted', ['sidebar-bg', 'sidebar-hover'], 4.5);
    check('sidebar-title', 'sidebar-title', 'sidebar-bg', 4.5);
    check('active-menu label', 'active-menu-contrast', 'active-menu', 4.5);
    check('active-menu fill', 'active-menu', 'sidebar-bg', 3);
    check('header-text', 'header-text', 'header-bg', 4.5);

    ['fg', 'fg-muted', 'fg-subtle'].forEach((role) => check(role, role, surfaces, 4.5));
    check('line-input', 'line-input', 'card-bg', 3);

    check('accent text', 'accent', surfaces, 4.5);
    ['accent', 'accent-hover', 'accent-active'].forEach((fill) => check('accent-contrast label', 'accent-contrast', fill, 4.5));
    check('accent-soft-text', 'accent-soft-text', ['accent-soft', 'accent-soft-hover', ...surfaces], 4.5);
    check('accent-ring', 'accent-ring', ['card-bg', 'dashboard-bg'], 3);

    ['success', 'warning', 'info', 'danger-fg'].forEach((status) => check(`${status} text`, status, surfaces, 4.5));
    ['danger', 'danger-hover', 'danger-active'].forEach((fill) => check('danger-contrast label', 'danger-contrast', fill, 4.5));
    check('danger-ring', 'danger-ring', ['card-bg', 'dashboard-bg'], 3);

    ['blue', 'green', 'amber', 'red', 'violet', 'sky', 'orange', 'neutral'].forEach((name) => check(`chart-${name}`, `chart-${name}`, surfaces, 4.5));

    ['gray', 'slate'].forEach((family) => ['400', '500', '600', '700', '800', '900', '950'].forEach((step) => {
        check(`${family}-${step} text`, `color-${family}-${step}`, surfaces, 4.5);
    }));

    return { dark, rows, failures: rows.filter((r) => r.ratio + 1e-9 < r.min) };
}
