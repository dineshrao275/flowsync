# Theming and colour contrast

## Root cause
Admin-picked theme colours (any hex) and the light/dark mode were applied as raw CSS variables, while components used fixed grey/colour utilities. Any combination the presets did not anticipate produced unreadable text.

## Design
- `resources/js/theme.js` `resolveTheme()` is the single engine. It derives every CSS custom property from the admin picks plus the mode, and nudges each foreground (hue preserved) until it reaches WCAG AA (4.5:1 text, 3:1 UI) on every background it sits on.
  - The scheme (dark/light neutral scale) follows the card luminance. A dark card in "light" mode gets the dark neutral scale.
  - Accent text, labels on accent fills, hover/active states, sidebar text/muted/title, active-menu label, status and danger tokens, chart palette (`--chart-*`) and the grey/slate ramp are all computed.
  - `adjustments` lists what was changed; the theme drawer shows it (`shell.theme.contrast.*`).
- `resources/js/themeAudit.js` audits a resolved theme; `theme.test.js` checks all presets, 300 seeded random themes and hostile themes (0 failures).
- `resources/css/app.css`: AA light text steps, `.dark` palette remaps, first-paint tokens, `.chip-tint` for data-coloured chips (worst case >= 4.5, unit-tested).
- Rules for new code: use semantic tokens (`text-fg*`, `bg-surface`, status roles, `TONES`), never `dark:text-gray-100/200/300` (the `.dark` class already remaps the grey scale, so these double-invert), never whole-row `opacity-*` on text, use `chip-tint` for data colours.

## Verification
- Vitest: `npx vitest run resources/js` — all green. ESLint: 0 errors / 122 warnings (baseline). `npm run build` OK.
- Real browser (Playwright + axe `color-contrast`): `THEME_AUDIT=1 npx playwright test tests/e2e/theme-contrast.spec.js` (`AUDIT_THEMES=0,1` to subset). 6 themes (Indigo Slate, Emerald Dark, Rose Night, Blue Steel, two hostile custom themes) x light/dark x ~20 screens.
  - Before: 433 violating nodes (2 themes, animation-inflated). After fixes: 0 across the 4 preset themes and 0 for the custom ones once GSAP hero reveals have settled.
  - The audit disables CSS animations and waits for `data-hero-reveal` GSAP tweens; mid-animation readings are not real defects.
