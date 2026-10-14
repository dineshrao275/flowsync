/**
 * Public marketing site behaviour. Vanilla JS on purpose — the public site is
 * server-rendered for SEO and Core Web Vitals, so it ships no framework here.
 */
document.addEventListener('DOMContentLoaded', () => {
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
                    throw new Error(first || 'Something went wrong — please try again.');
                }

                form.innerHTML =
                    '<div class="rounded-2xl bg-emerald-50 px-6 py-10 text-center">' +
                    '<p class="text-lg font-bold text-emerald-900">Thanks — we will be in touch.</p>' +
                    '<p class="mt-1 text-sm text-emerald-700">We usually reply within one business day.</p>' +
                    '</div>';
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
