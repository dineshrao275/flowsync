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
});
