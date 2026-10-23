# Website CMS and public marketing site

> Moved verbatim from `AGENTS.md`. Sections below keep their original headings.

## Website management / CMS + public site (Phase 14, item 11)
- **SPA moved to `/app`** — `BrowserRouter basename="/app"` in `resources/js/App.jsx`; the root
  `/` path now belongs to the public marketing site. `services/api.js` 401 redirect targets
  `/app/login`. `tests/Feature/ExampleTest.php` asserts `GET /app` (the old `GET /` needed the system DB).
- **`website_pages`** (system DB, migration `2026_09_24_000019`) = DB-backed CMS pages:
  `slug` unique, `title`, `content` JSON blocks (ordered array of `hero|features|text|cta`), `status`
  (`draft|published`), `seo_title`, `meta_description`, `og_image`, `sitemap_include`, `sort_order`,
  `published_at`. Model `App\Models\WebsitePage` (CentralConnection; `content` array cast;
  `isPublished()/publish()/unpublish()`). Seeded idempotently in `TenantSeeder::seedWebsitePages()`
  (home published with hero/features/cta; about draft; privacy/terms published but `sitemap_include=false`).
- **Admin CRUD** (super_admin group at `api/system/pages*`): `CmsController` index/show/store/update/
  destroy + `POST …/{page}/publish|unpublish`; writes central `audit_logs` rows
  `cms.page_created|updated|published|unpublished|deleted` (data `{slug,title,status}`); saving as
  `published` stamps `published_at` (preserved on later published edits); the `home` page can't be
  deleted (422). Frontend: `pages/CmsPages.jsx` at `/admin/pages` (sidebar "Website" under
  Administration) — table + `PageFormModal` with a typed block editor (add/reorder/remove blocks,
  inline CTA fields, per-block fields). Seeded idempotently in `TenantSeeder::seedWebsitePages()`
  (17 pages: home/features/pricing/solutions/integrations/customers/security/about/careers/contact/
  faq/changelog published + in sitemap; privacy/terms/dpa/cookies/refunds published but
  `sitemap_include=false`), `seedWebsiteConfig()` (nav/footer/social/announcement/contact via
  `firstOrCreate` — admin owns after), and `seedBlogContent()` (Guides/Company categories + 2 posts).
- **Site chrome (header):** language menu (`<details data-dropdown>`, complete locales from `config/locales.php`, links `?locale=xx`), dark-mode toggle (`flowsync.site.theme` in localStorage, no-flash init script in `<head>`, default follows `prefers-color-scheme`) and an auth-aware button (`session()->has(auth()->guard('web')->getName())` → Dashboard `/app`, else Log in/Start free). Dark mode flips the slate/blue scale variables under `.dark` in `resources/css/site.css`; sections dark in both modes use `scale-fixed bg-ink` (never `bg-slate-950`). SPA auth pages show a full-page "Back to website" link (`AuthShell`).
- **Product tour (screenshots):** real app screens live in `resources/site-assets/product/*.webp` (captured from the seeded Pembroke tenant at 1440×900 @1.5x, webp). `App\\Support\\SiteShowcase::install()` / `php artisan site:import-showcase` (also called by `TenantSeeder` outside testing) imports them into the media library (`media/showcase-*.webp`) and adds a `showcase` block (tabbed browser-frame tour, `components/site/browser-frame`) + hero `media_id` + split-block images to `home`/`features` — additive only, never overwrites an editor's block. Blocks are editable in the CMS (`showcase` type with per-item image). Uploaded media needs `public/storage` (`storage:link`, now run by `docker/entrypoint.sh`).
- **Public server-rendered site** (`PublicSiteController`, no auth): `GET /` renders the published
  `home` page (404-ish text if unseeded), `GET /page/{slug}` (published only, drafts 404),
  `GET /sitemap.xml` (published pages with `sitemap_include=true`, home always first; `lastmod` from
  `updated_at`), `GET /robots.txt` (Allow all + Sitemap line). Blade: `resources/views/layouts/site.blade.php`
  (sticky glassy header, mobile menu, skip-link, dark footer; links to `/app/login` + `/app/register`) +
  `resources/views/public/page.blade.php` (block switch: hero/features/cta/text; text paragraphs split on
  double newlines) + shared `resources/views/components/site/` (`button` variants, `eyebrow`,
  `section-heading`) + designed `resources/views/errors/404.blade.php`. The public site ships its own
  Tailwind v4 entry `resources/css/site.css` (`@source` scoped to the site views) and a framework-free
  `resources/js/site.js` (mobile menu + Escape/resize handling), both registered in `vite.config.js`.
  Blocks: `hero|features|text|richtext|cta` + `logo_cloud|stats|steps|split|testimonials|team|video`
  (media via `components/site/img.blade.php`, bulk-loaded by `App\Support\SiteMedia`, YouTube/Vimeo-only
  embeds) + `pricing` (live active `SubscriptionPlan` cards with `module_meta` labels, optional product
  filter) + `faq` (`<details>` accordion feeding the FAQPage schema) + `comparison` (yes→✓/no→— table).
  SEO ships in `components/site/seo-head.blade.php` (canonical, robots, og:*/twitter:*, favicon
  `public/favicon.svg`, theme-color) + `components/site/json-ld.blade.php` (Organization + WebSite on
  every page, BreadcrumbList on non-home pages via `$crumbs`, FAQPage from answered items). Pages live at top-level `/{slug}`
  (`site.page` route, registered last; `config/website.php` `reserved_slugs` can never become pages and
  404 in the controller regardless); legacy `/page/{slug}` 301s to the canonical URL, and the route
  fallback resolves `website_redirects` (`WebsiteRedirect`, auto-created on slug rename, `hits` counted)
  before the designed 404 — single-segment redirects resolve in `topPage()` because `/{slug}` matches
  before the fallback. The fallback lets `api/*`, `storage/*`, `build/*`, `up` keep their own 404 shape.
  Redirect CRUD at `api/system/redirects*` (`content.view`/`content.manage`). The `media` library
  (system table, public disk; raster-only uploads ≤5MB, SVGs refused, dimensions + alt recorded) backs
  public-site images via `api/system/media*` (`MediaController`: searchable list, alt update, delete removes
  the file); SPA `pages/MediaLibrary.jsx` at `/admin/media` + reusable `components/cms/MediaPicker.jsx`
  (browser mounts only while open, so no request fires until needed). Global site config lives in
  `website.*` platform settings via `App\Support\SiteConfig` (one `whereIn` query per render, no static
  memo): logo (Media row), tagline, header nav, footer columns, social profiles, GA4 + Search Console
  IDs, contact/sales emails, announcement bar, and raw custom head/body — all consumed by the layout
  (which self-loads `SiteConfig` so error pages render too) and edited on `pages/WebsiteSettings.jsx`
  at `/admin/site-settings` (`api/system/website-settings`, `SiteHref` rule rejects `javascript:`-style
  targets). `website_pages` also carries per-page `canonical_url`, `noindex` (excluded from sitemap),
  `og_title`, `og_description` (all editable in the page form's SEO section); drafts preview through a
  signed `preview/{page}` URL (`site.preview`, 24h, `X-Robots-Tag: noindex` — unsigned is 403); pages
  duplicate as drafts via `POST system/pages/{page}/duplicate` (`-copy` slug walk). The block editor
  gained a `richtext` type (shared TipTap `RichTextEditor`, HTML rendered server-side), and the page
  edit form always loads the full row via `show` — the list payload has no blocks, so editing from it
  would save back an empty list. The blog (`blog_categories` + `blog_posts`, cover via media FK, TipTap
  body, computed reading time, SEO parity columns) ships CRUD + publish lifecycle at
  `api/system/blog*` (SPA `pages/BlogPosts.jsx` at `/admin/blog` with inline category manager) and a
  public surface (`BlogController`: `/blog`, `/blog/category/{slug}`, `/blog/{post}`, `/blog/rss.xml`,
  signed `blog-preview/{post}`) with `BlogPosting` schema, related posts, and sitemap integration
  (posts + populated categories; noindex excluded). Contact/demo requests land in `marketing_leads`
  via unauthenticated `POST api/contact` (throttle + honeypot + queued `LeadReceivedMail` to the
  sales inbox — the mailable snapshots scalars and never names a prop `$message` (reserved in mail
  views for the message under construction; rendering it crashes the send)) and are worked in the
  SA inbox at `/admin/leads` (`api/system/leads*`,
  `leads.view`/`leads.manage` + `platform:sync-access` additive repair); the `contact_form` block
  posts via `site.js` fetch with the layout's CSRF meta and swaps to a thanks state inline. Content editing
  publishes live without code changes.
