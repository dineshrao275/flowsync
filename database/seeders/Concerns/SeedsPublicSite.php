<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\PlatformSetting;
use App\Models\WebsitePage;

/** The public marketing site content (pages, site config, blog) shared by the demo and production seeders. */
trait SeedsPublicSite
{
    protected function seedWebsitePages(): void
    {
        $pages = [
            [
                'slug' => 'home',
                'title' => 'FlowSync',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'FlowSync — Project Management, Time Tracking & HR in One Workspace',
                'meta_description' => 'FlowSync unites kanban boards, time tracking, sprints, HRMS, and realtime collaboration in one calm workspace. Start free — no credit card required.',
                'sitemap_include' => true,
                'sort_order' => 1,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Keep your whole team in motion.',
                        'subtext' => 'FlowSync unites task boards, time tracking, sprints, and HR in one calm workspace — for teams of five or five thousand.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'logo_cloud', 'heading' => 'Loved by delivery teams worldwide', 'logos' => [
                        ['name' => 'Acme Corp'], ['name' => 'Globex'], ['name' => 'Initech'],
                        ['name' => 'Umbrella'], ['name' => 'Stark Industries'], ['name' => 'Wayne Enterprises'],
                    ]],
                    ['type' => 'features', 'items' => [
                        ['title' => 'Realtime boards', 'text' => 'Drag tasks between columns and watch every project update live, for everyone, instantly.'],
                        ['title' => 'Time that adds up', 'text' => 'Track work against estimates, roll hours up to projects, and see where the week really went.'],
                        ['title' => 'Sprints & agile reports', 'text' => 'Plan sprints, burn down scope, and read velocity, lead time, and throughput at a glance.'],
                        ['title' => 'HRMS built in', 'text' => 'People records, leave, attendance, payroll, and performance live next to the work itself.'],
                        ['title' => 'Automations', 'text' => 'Route, assign, and notify on autopilot with rules your team can read — no code required.'],
                        ['title' => 'Search everything', 'text' => 'Jump to any task, project, workspace, or teammate with one keystroke.'],
                    ]],
                    ['type' => 'split', 'eyebrow' => 'Deliver', 'heading' => 'Boards that keep themselves honest',
                        'body' => 'WIP limits, blocked-task guards, and workflow rules hold the line while your team moves fast. Estimates, checklists, and dependencies ride along on every card.',
                        'cta' => ['label' => 'Explore features', 'href' => '/features']],
                    ['type' => 'stats', 'items' => [
                        ['value' => '99.99%', 'label' => 'Uptime SLA on paid plans'],
                        ['value' => '40+', 'label' => 'Countries shipping with FlowSync'],
                        ['value' => '1', 'label' => 'Workspace for projects and people'],
                    ]],
                    ['type' => 'steps', 'eyebrow' => 'How it works', 'heading' => 'Live in an afternoon', 'items' => [
                        ['title' => 'Create your workspace', 'text' => 'Sign up, name your team, and invite everyone in minutes.'],
                        ['title' => 'Import your work', 'text' => 'Bring projects, people, and history with guided CSV import.'],
                        ['title' => 'Ship on day one', 'text' => 'Boards, sprints, and time tracking work out of the box.'],
                    ]],
                    ['type' => 'testimonials', 'eyebrow' => 'Customers', 'heading' => 'Teams that stayed in motion', 'items' => [
                        ['quote' => 'We replaced three tools with FlowSync. Planning finally happens where the work happens.', 'name' => 'Ada Lovelace', 'role' => 'CTO, Acme Corp'],
                        ['quote' => 'Sprint reviews went from archaeology to a glance. The burndown tells the truth.', 'name' => 'Devon Park', 'role' => 'Engineering Lead, Globex'],
                        ['quote' => 'Leave, attendance, and payroll next to delivery changed how we run the company.', 'name' => 'Maria Santos', 'role' => 'People Ops, Initech'],
                    ]],
                    ['type' => 'pricing', 'eyebrow' => 'Pricing', 'heading' => 'Simple pricing that scales with you', 'lead' => 'Start free. Upgrade when the team grows — every plan includes unlimited viewers.'],
                    ['type' => 'faq', 'heading' => 'Questions, answered', 'items' => [
                        ['q' => 'Is there a free trial?', 'a' => 'Yes — every paid plan starts with a 14-day free trial, no credit card required.'],
                        ['q' => 'Can I buy task management and HR separately?', 'a' => 'Yes. FlowSync sells TMS and HRMS as separate products or as one suite, and you can split or merge subscriptions at any time.'],
                        ['q' => 'How does onboarding work?', 'a' => 'A guided wizard collects your business profile, admin account, and plan, then provisions your private database in minutes.'],
                        ['q' => 'Is my data isolated?', 'a' => 'Every tenant runs in its own database — your rows never share tables with another company.'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Ready to stay in motion?',
                        'text' => 'Spin up a workspace in minutes — free trial on every plan, no card required.',
                        'cta' => ['label' => 'Create your workspace', 'href' => '/app/register']],
                ],
            ],
            [
                'slug' => 'features',
                'title' => 'Features',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Features — Kanban, Sprints, Time Tracking, HRMS & Automations | FlowSync',
                'meta_description' => 'Explore FlowSync features: realtime kanban boards, sprints with agile reports, time tracking, built-in HRMS, automations, and global search.',
                'sitemap_include' => true,
                'sort_order' => 2,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Everything delivery needs. Nothing it does not.',
                        'subtext' => 'One workspace for plans, people, and proof — designed to stay calm at scale.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'features', 'items' => [
                        ['title' => 'Kanban & list views', 'text' => 'Board, list, swimlanes, WIP limits, and deep links to every card.'],
                        ['title' => 'Sprints & reports', 'text' => 'Committed scope, burndown, velocity, lead/cycle time, throughput.'],
                        ['title' => 'Time tracking', 'text' => 'Work logs with estimates, billable rollups, and per-project summaries.'],
                        ['title' => 'HRMS suite', 'text' => 'Directory, onboarding, leave, attendance, payroll, expenses, assets, and reviews.'],
                        ['title' => 'Automations & webhooks', 'text' => 'Rules, time triggers, and signed webhooks that keep systems in sync.'],
                        ['title' => 'Docs & collaboration', 'text' => 'Comments, mentions, attachments, checklists, and activity timelines.'],
                    ]],
                    ['type' => 'split', 'eyebrow' => 'Proof', 'heading' => 'Reports your Monday meeting will love',
                        'body' => 'Overviews, analytics, and time summaries answer “where are we?” before anyone asks. Export anything; every number traces back to its rows.',
                        'cta' => ['label' => 'See pricing', 'href' => '/pricing']],
                    ['type' => 'cta', 'heading' => 'Try every feature free for 14 days.',
                        'text' => 'No card. No sales call. Just your team, in motion.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                ],
            ],
            [
                'slug' => 'pricing',
                'title' => 'Pricing',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Pricing — Simple Plans for Teams of Any Size | FlowSync',
                'meta_description' => 'FlowSync pricing: start free, upgrade as you grow. TMS and HRMS sold separately or as a suite. 14-day trial on every paid plan.',
                'sitemap_include' => true,
                'sort_order' => 3,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Pricing that scales with you.',
                        'subtext' => 'Start free. Pay per teammate when you grow. Cancel anytime with two clicks.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'pricing', 'heading' => 'Pick your plan'],
                    ['type' => 'comparison', 'heading' => 'Compare plans', 'columns' => [
                        ['heading' => 'Starter'], ['heading' => 'Pro'], ['heading' => 'Business'],
                    ], 'rows' => [
                        ['label' => 'Projects & boards', 'values' => ['yes', 'yes', 'yes']],
                        ['label' => 'Time tracking', 'values' => ['no', 'yes', 'yes']],
                        ['label' => 'Sprints & reports', 'values' => ['no', 'yes', 'yes']],
                        ['label' => 'HRMS suite', 'values' => ['no', 'Core', 'Full + payroll']],
                        ['label' => 'Support', 'values' => ['Community', 'Email', 'Priority']],
                    ]],
                    ['type' => 'faq', 'heading' => 'Pricing questions', 'items' => [
                        ['q' => 'Do unused seats roll over?', 'a' => 'Seats are per-teammate per month. Remove a teammate and the seat frees up immediately.'],
                        ['q' => 'Can I mix TMS and HRMS plans?', 'a' => 'Yes — subscribe each product separately, or take the suite and save.'],
                        ['q' => 'What happens when the trial ends?', 'a' => 'You keep your data. Pick a plan to continue, or drop to the free tier.'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Still deciding? Talk to us.',
                        'text' => 'Tell us about your team and we will recommend the right shape.',
                        'cta' => ['label' => 'Contact sales', 'href' => '/contact']],
                ],
            ],
            [
                'slug' => 'solutions',
                'title' => 'Solutions',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Solutions for Engineering, Marketing, Operations & HR | FlowSync',
                'meta_description' => 'See how engineering, marketing, operations, and HR teams run delivery and people ops on FlowSync.',
                'sitemap_include' => true,
                'sort_order' => 4,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Built for how your team actually works.',
                        'subtext' => 'Same workspace, shaped to the job — from sprint planning to performance reviews.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'split', 'eyebrow' => 'Engineering', 'heading' => 'Ship sprints without the spreadsheet',
                        'body' => 'Epics, story points, burndown, and velocity live where the code gets reviewed — next to the tasks themselves.',
                        'cta' => ['label' => 'Explore features', 'href' => '/features']],
                    ['type' => 'split', 'eyebrow' => 'Marketing', 'heading' => 'Campaigns from brief to launch', 'flip' => true,
                        'body' => 'Calendars, approvals, and asset checklists keep launches on date while leadership watches one dashboard.',
                        'cta' => ['label' => 'See customers', 'href' => '/customers']],
                    ['type' => 'split', 'eyebrow' => 'People teams', 'heading' => 'HR that sits next to delivery',
                        'body' => 'Onboarding, leave, attendance, and reviews share the same directory as the project roster. No more double entry.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'cta', 'heading' => 'See your team in FlowSync.',
                        'text' => 'Pick a plan, invite the team, import a CSV — live today.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                ],
            ],
            [
                'slug' => 'integrations',
                'title' => 'Integrations',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Integrations — API, Webhooks, Stripe & Payroll Exports | FlowSync',
                'meta_description' => 'Connect FlowSync to your stack: REST API with personal tokens, signed webhooks, Stripe billing, and CSV exports everywhere.',
                'sitemap_include' => true,
                'sort_order' => 5,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Plays well with your stack.',
                        'subtext' => 'API-first, webhook-native, and export-friendly — your data stays yours and stays moving.',
                        'cta' => ['label' => 'Read the docs', 'href' => '/blog']],
                    ['type' => 'features', 'items' => [
                        ['title' => 'REST API', 'text' => 'Personal tokens with scoped abilities, rate limits, and idempotent writes.'],
                        ['title' => 'Signed webhooks', 'text' => 'Task events delivered with HMAC signatures and visible retry history.'],
                        ['title' => 'Stripe billing', 'text' => 'Checkout, portals, proration, and dunning handled natively.'],
                        ['title' => 'CSV everywhere', 'text' => 'Users, attendance, reports, and audit trails export in one click.'],
                        ['title' => 'Realtime feed', 'text' => 'WebSocket events for boards, comments, and notifications.'],
                        ['title' => 'SSO-ready directory', 'text' => 'Central routing index keeps every login resolvable and auditable.'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Build on FlowSync.',
                        'text' => 'Tokens, docs, and examples ship in the box.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                ],
            ],
            [
                'slug' => 'customers',
                'title' => 'Customers',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Customers — Teams Shipping with FlowSync',
                'meta_description' => 'Hear from engineering, delivery, and people teams running projects and HR on FlowSync.',
                'sitemap_include' => true,
                'sort_order' => 6,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Teams that stayed in motion.',
                        'subtext' => 'From five-person startups to global delivery orgs.',
                        'cta' => ['label' => 'Join them', 'href' => '/app/register']],
                    ['type' => 'stats', 'items' => [
                        ['value' => '40+', 'label' => 'Countries'],
                        ['value' => '99.99%', 'label' => 'Uptime SLA'],
                        ['value' => '14 days', 'label' => 'Median time to value'],
                    ]],
                    ['type' => 'testimonials', 'heading' => 'In their words', 'items' => [
                        ['quote' => 'We replaced three tools with FlowSync. Planning finally happens where the work happens.', 'name' => 'Ada Lovelace', 'role' => 'CTO, Acme Corp'],
                        ['quote' => 'Sprint reviews went from archaeology to a glance. The burndown tells the truth.', 'name' => 'Devon Park', 'role' => 'Engineering Lead, Globex'],
                        ['quote' => 'Leave, attendance, and payroll next to delivery changed how we run the company.', 'name' => 'Maria Santos', 'role' => 'People Ops, Initech'],
                        ['quote' => 'Onboarding a new hire is a checklist now, not a scavenger hunt.', 'name' => 'Tom Becker', 'role' => 'COO, Umbrella'],
                        ['quote' => 'The audit trail alone paid for the year during our compliance review.', 'name' => 'Priya Nair', 'role' => 'Finance, Stark Industries'],
                        ['quote' => 'Support answers in hours, and the changelog keeps coming.', 'name' => 'Leo Wayne', 'role' => 'Founder, Wayne Enterprises'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Write your own story.',
                        'text' => 'Free trial, no card, live today.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                ],
            ],
            [
                'slug' => 'security',
                'title' => 'Security',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Security — Isolation, Audit Trails & Access Controls | FlowSync',
                'meta_description' => 'How FlowSync protects your data: per-tenant databases, scope-aware RBAC, audit hash chains, 2FA, and consent-based support access.',
                'sitemap_include' => true,
                'sort_order' => 7,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Security is the architecture.',
                        'subtext' => 'Isolation by design, evidence by default — not a checklist taped on after.',
                        'cta' => ['label' => 'Read the FAQ', 'href' => '/faq']],
                    ['type' => 'features', 'items' => [
                        ['title' => 'Per-tenant databases', 'text' => 'Your rows never share tables with another company. Ever.'],
                        ['title' => 'Scope-aware RBAC', 'text' => 'Own, assigned, and all — enforced in queries, policies, and exports.'],
                        ['title' => 'Hash-chained audit', 'text' => 'Tamper-evident trails with verification and signed exports.'],
                        ['title' => 'Two-factor auth', 'text' => 'TOTP with recovery codes and per-role enforcement.'],
                        ['title' => 'Consent-based access', 'text' => 'Support sessions are time-boxed, read-scoped, and revocable.'],
                        ['title' => 'Hardened transport', 'text' => 'CSP, HSTS, signed downloads, and throttled public endpoints.'],
                    ]],
                    ['type' => 'richtext', 'html' => '<h2>Compliance posture</h2><p>FlowSync is built for teams that answer to auditors: every sensitive read is access-logged, exports are permission-gated and throttled, and retention policies prune data on schedule. Bring your questionnaire — most answers are one screenshot.</p>'],
                    ['type' => 'cta', 'heading' => 'Questions for our security team?',
                        'text' => 'We answer fast and in plain language.',
                        'cta' => ['label' => 'Contact us', 'href' => '/contact']],
                ],
            ],
            [
                'slug' => 'about',
                'title' => 'About',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'About FlowSync — Calm Software for Teams in Motion',
                'meta_description' => 'FlowSync builds calm, fast software for delivery and people teams. Meet the mission and the team behind it.',
                'sitemap_include' => true,
                'sort_order' => 8,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Calm software for teams in motion.',
                        'subtext' => 'We believe tools should absorb chaos, not create it.',
                        'cta' => ['label' => 'See open roles', 'href' => '/careers']],
                    ['type' => 'richtext', 'html' => '<h2>Why FlowSync exists</h2><p>Delivery tools forgot that projects are made of people. HR tools forgot that people make projects. We build the workspace where both live — fast, honest, and boring in all the right ways.</p>'],
                    ['type' => 'stats', 'items' => [
                        ['value' => '2019', 'label' => 'First commit'],
                        ['value' => '40+', 'label' => 'Countries served'],
                        ['value' => '100%', 'label' => 'Remote team'],
                    ]],
                    ['type' => 'team', 'heading' => 'The team', 'members' => [
                        ['name' => 'Ava Stone', 'role' => 'Founder & CEO'],
                        ['name' => 'Ben Carter', 'role' => 'Head of Engineering'],
                        ['name' => 'Chloe Dubois', 'role' => 'Design Lead'],
                        ['name' => 'David Okafor', 'role' => 'Head of People'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Come build with us.',
                        'text' => 'We are hiring across engineering, design, and support.',
                        'cta' => ['label' => 'See open roles', 'href' => '/careers']],
                ],
            ],
            [
                'slug' => 'careers',
                'title' => 'Careers',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Careers — Join the FlowSync Team (Remote)',
                'meta_description' => 'FlowSync is hiring across engineering, design, and support. Remote-first, calm by design.',
                'sitemap_include' => true,
                'sort_order' => 9,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Do the best work of your career.',
                        'subtext' => 'Remote-first, async-friendly, and allergic to busywork.',
                        'cta' => ['label' => 'Say hello', 'href' => '/contact']],
                    ['type' => 'features', 'items' => [
                        ['title' => 'Remote-first', 'text' => 'Work from anywhere in overlapping time zones.'],
                        ['title' => 'Calm by policy', 'text' => 'No status theater, no meeting Mondays, no heroics.'],
                        ['title' => 'Real ownership', 'text' => 'Small teams, clear scope, shipped weekly.'],
                    ]],
                    ['type' => 'richtext', 'html' => '<h2>Open roles</h2><p><strong>Senior Backend Engineer (Laravel)</strong> — own delivery surfaces end to end.<br><strong>Product Designer</strong> — shape the calmest boards on the internet.<br><strong>Support Engineer</strong> — answer fast, fix faster, write it down.</p><p>Send a note about the role and the work you are proud of — we read everything.</p>'],
                    ['type' => 'contact_form', 'heading' => 'Apply in one message', 'lead' => 'Role, links, and two paragraphs. That is the whole process.', 'source' => 'contact'],
                ],
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Contact & Book a Demo | FlowSync',
                'meta_description' => 'Talk to the FlowSync team: book a live demo, ask about plans, or get migration help. Replies within one business day.',
                'sitemap_include' => true,
                'sort_order' => 10,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Talk to a human.',
                        'subtext' => 'Demos, plans, migrations, security questionnaires — we answer within one business day.',
                        'cta' => ['label' => 'See pricing', 'href' => '/pricing']],
                    ['type' => 'contact_form', 'heading' => 'Book a demo', 'lead' => 'Thirty minutes, your questions, our screen. No slide decks.', 'source' => 'demo'],
                    ['type' => 'faq', 'heading' => 'Before you write', 'items' => [
                        ['q' => 'Do you offer onboarding help?', 'a' => 'Yes — guided imports and a launch checklist come with every trial, and our team helps on annual plans.'],
                        ['q' => 'Can we self-host?', 'a' => 'FlowSync Cloud is the supported path today; reach out if you have sovereignty requirements.'],
                    ]],
                ],
            ],
            [
                'slug' => 'faq',
                'title' => 'FAQ',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'FAQ — Trials, Plans, Security & Data | FlowSync',
                'meta_description' => 'Answers about FlowSync trials, plans, TMS/HRMS products, data isolation, onboarding, and support.',
                'sitemap_include' => true,
                'sort_order' => 11,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Frequently asked questions.',
                        'subtext' => 'Short answers, no fine print.',
                        'cta' => ['label' => 'Still stuck? Ask us', 'href' => '/contact']],
                    ['type' => 'faq', 'heading' => 'Plans & trials', 'items' => [
                        ['q' => 'Is there a free trial?', 'a' => 'Yes — every paid plan starts with a 14-day free trial, no credit card required.'],
                        ['q' => 'Can I buy TMS and HRMS separately?', 'a' => 'Yes. Subscribe each product separately or take the suite; split or merge subscriptions anytime.'],
                        ['q' => 'What happens to my data if I cancel?', 'a' => 'It stays exportable. Cancel keeps read access through the paid period, then drops you to the free tier.'],
                    ]],
                    ['type' => 'faq', 'heading' => 'Security & data', 'items' => [
                        ['q' => 'Is my data isolated?', 'a' => 'Every tenant runs in its own database — rows never share tables with another company.'],
                        ['q' => 'Do you support 2FA?', 'a' => 'Yes — TOTP with recovery codes, enforceable per role.'],
                        ['q' => 'Can support see my data?', 'a' => 'Only with a time-boxed, consent-based grant you approve — and you can revoke it mid-session.'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Ask us anything.',
                        'text' => 'A human replies within one business day.',
                        'cta' => ['label' => 'Contact us', 'href' => '/contact']],
                ],
            ],
            [
                'slug' => 'changelog',
                'title' => 'Changelog',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Changelog — What Shipped in FlowSync',
                'meta_description' => 'Every FlowSync release: new blocks, HRMS modules, automations, and fixes. Shipped weekly.',
                'sitemap_include' => true,
                'sort_order' => 12,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Shipped weekly. Read monthly.',
                        'subtext' => 'The highlights — the full history lives in the app.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'richtext', 'html' => '<h2>November 2026</h2><p><strong>Marketing site v2</strong> — new public site with live pricing, blog, and lead capture, all editable from the admin panel.<br><strong>Talent reviews</strong> — calibration grids and 360 summaries land in HRMS.</p><h2>October 2026</h2><p><strong>Automations</strong> — project rules with time triggers.<br><strong>Sprints</strong> — burndown, velocity, and throughput reports.</p>'],
                    ['type' => 'cta', 'heading' => 'Get the changelog by email.',
                        'text' => 'One short email per release. Never more.',
                        'cta' => ['label' => 'Contact us', 'href' => '/contact']],
                ],
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Privacy Policy | FlowSync',
                'meta_description' => 'How FlowSync collects, uses, and protects your data.',
                'sitemap_include' => false,
                'sort_order' => 20,
                'published_at' => now(),
                'content' => [['type' => 'richtext', 'html' => '<h2>Privacy Policy</h2><p>Your data lives in your own database — that is the architecture, not a promise in a PDF. We collect the minimum needed to run your workspace (account details, usage metrics for billing, and support correspondence), never sell personal data, and delete tenant data on verified request once legal holds clear.</p><p>Marketing-site analytics, when enabled, are aggregate and cookie-light. Contact us anytime to export or erase your data.</p>']],
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms of Service',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Terms of Service | FlowSync',
                'meta_description' => 'The rules for using FlowSync: acceptable use, billing, and liability.',
                'sitemap_include' => false,
                'sort_order' => 21,
                'published_at' => now(),
                'content' => [['type' => 'richtext', 'html' => '<h2>Terms of Service</h2><p>Use FlowSync responsibly: no illegal content, no abuse of shared infrastructure, no circumventing plan limits. Subscriptions bill per the plan cycle; cancel anytime and keep access through the paid period. The service ships as-is with the uptime commitments of your plan.</p>']],
            ],
            [
                'slug' => 'dpa',
                'title' => 'Data Processing Agreement',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Data Processing Agreement (DPA) | FlowSync',
                'meta_description' => 'FlowSync DPA: processor commitments, sub-processors, and data subject rights.',
                'sitemap_include' => false,
                'sort_order' => 22,
                'published_at' => now(),
                'content' => [['type' => 'richtext', 'html' => '<h2>Data Processing Agreement</h2><p>FlowSync processes tenant data as a processor under your instructions: per-tenant databases, access logging, breach notification within 72 hours, and deletion on contract end. Sub-processors are limited to infrastructure and payment providers, listed here and updated with notice.</p>']],
            ],
            [
                'slug' => 'cookies',
                'title' => 'Cookie Policy',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Cookie Policy | FlowSync',
                'meta_description' => 'Which cookies FlowSync uses and why: session, preferences, and optional analytics.',
                'sitemap_include' => false,
                'sort_order' => 23,
                'published_at' => now(),
                'content' => [['type' => 'richtext', 'html' => '<h2>Cookie Policy</h2><p>The app needs a session cookie to keep you signed in and a preference cookie for theme and sidebar state. The marketing site sets no marketing cookies itself; enabling Google Analytics adds its standard measurement cookies, disclosed here.</p>']],
            ],
            [
                'slug' => 'refunds',
                'title' => 'Refund Policy',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Refund Policy | FlowSync',
                'meta_description' => 'FlowSync refunds: 14-day trial, monthly cancel-anytime, annual pro-rata terms.',
                'sitemap_include' => false,
                'sort_order' => 24,
                'published_at' => now(),
                'content' => [['type' => 'richtext', 'html' => '<h2>Refund Policy</h2><p>Trials are free and need no card, so there is nothing to refund. Monthly plans cancel anytime with access through the paid period. Annual plans refund pro-rata within the first 30 days. Failed payments retry with notice before any suspension.</p>']],
            ],
        ];

        foreach ($pages as $page) {
            WebsitePage::updateOrCreate(
                ['slug' => $page['slug']],
                $page
            );
        }
    }

    protected function seedWebsiteConfig(): void
    {
        $settings = [
            'website.tagline' => 'Project management in motion — boards, time tracking, and HR in one calm workspace.',
            'website.nav' => json_encode([
                ['label' => 'Features', 'href' => '/features'],
                ['label' => 'Pricing', 'href' => '/pricing'],
                ['label' => 'Customers', 'href' => '/customers'],
                ['label' => 'Blog', 'href' => '/blog'],
                ['label' => 'About', 'href' => '/about'],
            ]),
            'website.footer_columns' => json_encode([
                ['heading' => 'Product', 'links' => [
                    ['label' => 'Features', 'href' => '/features'],
                    ['label' => 'Pricing', 'href' => '/pricing'],
                    ['label' => 'Integrations', 'href' => '/integrations'],
                    ['label' => 'Changelog', 'href' => '/changelog'],
                    ['label' => 'Start free', 'href' => '/app/register'],
                ]],
                ['heading' => 'Company', 'links' => [
                    ['label' => 'About', 'href' => '/about'],
                    ['label' => 'Customers', 'href' => '/customers'],
                    ['label' => 'Careers', 'href' => '/careers'],
                    ['label' => 'Contact', 'href' => '/contact'],
                    ['label' => 'Blog', 'href' => '/blog'],
                ]],
                ['heading' => 'Resources', 'links' => [
                    ['label' => 'Security', 'href' => '/security'],
                    ['label' => 'FAQ', 'href' => '/faq'],
                    ['label' => 'Solutions', 'href' => '/solutions'],
                    ['label' => 'Sitemap', 'href' => '/sitemap.xml'],
                ]],
                ['heading' => 'Legal', 'links' => [
                    ['label' => 'Privacy', 'href' => '/privacy'],
                    ['label' => 'Terms', 'href' => '/terms'],
                    ['label' => 'DPA', 'href' => '/dpa'],
                    ['label' => 'Cookies', 'href' => '/cookies'],
                    ['label' => 'Refunds', 'href' => '/refunds'],
                ]],
            ]),
            'website.contact_email' => 'hello@flowsync.test',
            'website.announcement' => 'New: HRMS talent reviews are live — calibration grids, 360 summaries, and more.',
            'website.announcement_href' => '/features',
        ];

        // firstOrCreate: global config ships once; the admin owns it after.
        foreach ($settings as $key => $value) {
            PlatformSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    protected function seedBlogContent(): void
    {
        $guides = BlogCategory::updateOrCreate(
            ['slug' => 'guides'],
            ['name' => 'Guides', 'description' => 'How to run delivery and people ops on FlowSync.', 'sort_order' => 1]
        );
        $company = BlogCategory::updateOrCreate(
            ['slug' => 'company'],
            ['name' => 'Company', 'description' => 'News and notes from the FlowSync team.', 'sort_order' => 2]
        );

        $posts = [
            [
                'slug' => 'first-project-in-flowsync',
                'category_id' => $guides->id,
                'title' => 'Your first project in FlowSync, in fifteen minutes',
                'excerpt' => 'Workspace, board, first sprint, first time log — the fastest path from signup to shipping.',
                'body' => '<h2>Start with one board</h2><p>Create a workspace for your team, add a project, and let the default workflow carry your first ten tasks. Resist the urge to design the perfect process on day one — the board teaches you what you need.</p><h2>Log time from the start</h2><p>Estimates plus work logs turn “are we on track?” from a feeling into a number. Two weeks of honest logs beats any planning poker session.</p><h2>Invite early, permission late</h2><p>Bring the team in as viewers, then promote the people doing the work. FlowSync’s scopes keep every row visible to exactly who needs it.</p>',
                'author_name' => 'Team FlowSync',
                'status' => BlogPost::STATUS_PUBLISHED,
                'seo_title' => 'Your First Project in FlowSync in 15 Minutes — Guide',
                'meta_description' => 'The fastest path from FlowSync signup to shipping: one board, honest time logs, and right-sized permissions.',
                'published_at' => now(),
            ],
            [
                'slug' => 'hrms-talent-reviews-are-live',
                'category_id' => $company->id,
                'title' => 'Talent reviews are live in HRMS',
                'excerpt' => 'Calibration grids, 360 summaries, and anonymity guardrails — performance season without the spreadsheet.',
                'body' => '<h2>Calibrate, don’t guess</h2><p>The new Talent page grids every review in a cycle so managers calibrate together, with distribution views that surface skew before it ships.</p><h2>360s people trust</h2><p>Feedback summaries stay numbers-only with anonymity floors — no score, no leak, no retaliation math.</p><h2>Finalize with a nudge</h2><p>Closing a review notifies the right people automatically. Performance season, minus the season.</p>',
                'author_name' => 'Team FlowSync',
                'status' => BlogPost::STATUS_PUBLISHED,
                'seo_title' => 'Talent Reviews Are Live in FlowSync HRMS',
                'meta_description' => 'Calibration grids, 360 summaries, and anonymity guardrails land in FlowSync HRMS.',
                'published_at' => now(),
            ],
        ];

        foreach ($posts as $post) {
            $body = $post['body'];
            unset($post['body']);
            BlogPost::updateOrCreate(
                ['slug' => $post['slug']],
                $post + [
                    'body' => $body,
                    'reading_minutes' => max(1, (int) round(str_word_count(strip_tags($body)) / 200)),
                ]
            );
        }
    }
}
