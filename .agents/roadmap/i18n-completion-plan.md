# i18n completion plan (Spanish as the first non-English locale)

Audit at `df4aaa86`. i18n Phases 0-6 shipped the **framework and the English source**: locale resolution +
`PUT /api/language`, i18next in the SPA, 4,429 English leaf strings in `resources/js/locales/en/*.json`
(170 of 189 JSX files migrated), `lang/en/*.php`, the public-site chrome and task e-mail copy.
`es` is registered in `config/locales.php` with `complete => false`, so **no user can pick it yet**.

## What is left (gap list)

| # | Gap | Size |
|---|-----|------|
| G1 | Hand-built English in formatters: relative time (`just now`, `3 hours ago`), `formatPrice` (`/mo billed annually`), bare `toLocale*()` calls with no/`[]` locale (Checklist, DocumentList, Leads, Attendance, ImpersonationBanner, Subscription counts) | ~12 sites |
| G2 | **No Spanish SPA dictionaries** — i18n.js only registers `en`; nothing lazy-loads another locale | 4,429 strings (common 242, auth 135, dashboard 147, tms 580, admin 1,139, hrms 2,186) |
| G3 | Spanish backend files missing: `validation`, `pagination`, `site` (only auth/passwords/notifications exist) | 3 files (~320 lines) |
| G4 | Backend response messages are English literals (`'message' => '...'`, `ValidationException::withMessages`, `abort(…,'…')`) | ~340 + ~230 sites, 118 files |
| G5 | Stragglers: 5 literal `label=/placeholder=` attributes, `errors/404.blade.php`, SPA `components/ui` (14 files, mostly string-free primitives) | small |
| G6 | Guards + release: key-parity test en↔es (SPA + PHP), interpolation-placeholder parity, flip `complete => true`, docs | — |

Out of scope by design (AGENTS.md): CMS page/block bodies, blog posts, `SiteConfig` labels (tenant/deployment
content), `LeadReceivedMail` (internal inbox), RTL (no RTL language in the pipeline).

## Phases

- **L1 — Locale-correct formatters (G1).** Relative time via `Intl.RelativeTimeFormat`, price suffixes as
  dictionary keys, every `toLocale*` call routed through `intlTag`.
- **L2 — SPA loader + Spanish `common`/`auth`/`dashboard` (G2a).** Per-locale resource bundles (lazy
  `import()` so `es` costs nothing for English users), `applyServerLocale` awaits the bundle.
- **L3 — Spanish `tms` (G2b).**
- **L4 — Spanish `admin` (G2c).**
- **L5 — Spanish `hrms` (G2d).**
- **L6 — Spanish backend files (G3) + straggler strings (G5).**
- **L7 — Backend response messages (G4)** → `lang/en/messages.php` keys + `lang/es`.
- **L8 — Parity guards, flip `es` to complete, docs (G6).** Full suite once, at the end.

Delivery rule (AGENTS.md / memory): one commit + push per phase; no per-phase test runs — vitest/PHPUnit/build run
once in L8, then fix.
