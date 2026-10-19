/**
 * Public marketing site behaviour. Vanilla JS on purpose — the public site is
 * server-rendered for SEO and Core Web Vitals, so it ships no framework here.
 * Copy comes from the `lang/en/site.php` catalog, rendered into the page as a
 * JSON island (`#site-i18n`) so a locale switch needs no client framework.
 */
function readSiteI18n() {
    const node = document.getElementById('site-i18n');
    if (!node) return {};
    try {
        return JSON.parse(node.textContent) || {};
    } catch {
        return {};
    }
}

function thanksState(i18n) {
    const wrapper = document.createElement('div');
    wrapper.className = 'rounded-2xl bg-emerald-50 px-6 py-10 text-center';

    const heading = document.createElement('p');
    heading.className = 'text-lg font-bold text-emerald-900';
    heading.textContent = i18n.contactThanks || '';

    const body = document.createElement('p');
    body.className = 'mt-1 text-sm text-emerald-700';
    body.textContent = i18n.contactThanksBody || '';

    wrapper.append(heading, body);
    return wrapper;
}

document.addEventListener('DOMContentLoaded', () => {
    const i18n = readSiteI18n();

    // Theme toggle: persists the explicit choice; with none stored the page follows the OS.
    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const dark = document.documentElement.classList.toggle('dark');
            try {
                localStorage.setItem('flowsync.site.theme', dark ? 'dark' : 'light');
            } catch {
                /* storage blocked: the choice just lasts for this page view */
            }
            document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-pressed', dark ? 'true' : 'false'));
        });
        toggle.setAttribute('aria-pressed', document.documentElement.classList.contains('dark') ? 'true' : 'false');
    });

    // Language menu (<details>): close on outside click or Escape.
    const dropdowns = document.querySelectorAll('details[data-dropdown]');
    document.addEventListener('click', (event) => {
        dropdowns.forEach((d) => {
            if (d.open && !d.contains(event.target)) d.open = false;
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') dropdowns.forEach((d) => { d.open = false; });
    });

    const button = document.getElementById('site-menu-button');
    const menu = document.getElementById('site-mobile-menu');

    if (!button || !menu) {
        return;
    }

    function setOpen(open) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        menu.classList.toggle('hidden', !open);
        document.body.classList.toggle('overflow-hidden', open);
    }

    button.addEventListener('click', () => {
        setOpen(menu.classList.contains('hidden'));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.classList.contains('hidden')) {
            setOpen(false);
            button.focus();
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024 && !menu.classList.contains('hidden')) {
            setOpen(false);
        }
    });

    // Contact/demo forms post to the JSON endpoint with the CSRF token and
    // swap to a thanks state inline — no page reload, no redirect hop.
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    document.querySelectorAll('[data-contact-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const error = form.querySelector('[data-contact-error]');
            const submit = form.querySelector('[data-contact-submit]');
            if (error) error.classList.add('hidden');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Named access (form.elements.name) — `form.name` itself is the
            // form's own name property, not the field.
            const field = (name) => form.querySelector(`[name="${name}"]`)?.value.trim() || '';
            const payload = {
                name: field('name'),
                email: field('email'),
                company: field('company') || null,
                message: field('message'),
                source: form.dataset.source || 'contact',
                website: field('website'),
            };

            if (submit) submit.disabled = true;

            try {
                const response = await fetch('/api/contact', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                    },
                    body: JSON.stringify(payload),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                    throw new Error(first || i18n.contactError);
                }

                form.replaceChildren(thanksState(i18n));
            } catch (err) {
                if (error) {
                    error.textContent = err.message;
                    error.classList.remove('hidden');
                }
                if (submit) submit.disabled = false;
            }
        });
    });
});
