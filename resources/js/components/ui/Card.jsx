/**
 * Card hierarchy (Phase 2): `flat` is the default — a quiet container with
 * no hover lift, so pages of many cards don't shimmer as one SaaS-card-kit.
 * `raised` pops above the page (dialog-adjacent panels, hero stats);
 * `interactive` opts into the hover lift + pointer for clickable cards.
 */
const elevations = {
    flat: 'shadow-card',
    raised: 'shadow-popover',
    interactive: 'shadow-card hover:shadow-popover hover:-translate-y-0.5 cursor-pointer',
};

export default function Card({ title, subtitle, actions, children, className = '', dense = false, elevation = 'flat' }) {
    const body = dense ? 'px-5 py-4' : 'px-6 py-5';
    const header = dense ? 'px-5 py-3' : 'px-6 py-4';

    return (
        <div
            className={`rounded-xl border border-gray-200/70 bg-white transition-all duration-150 ${elevations[elevation] ?? elevations.flat} ${className}`}
            style={{ backgroundColor: 'var(--card-bg)' }}
        >
            {(title || actions) && (
                <div className={`flex items-center justify-between gap-4 border-b border-gray-100 ${header}`}>
                    <div>
                        {title && <h3 className={`font-semibold text-gray-900 ${dense ? 'text-sm' : 'text-base'}`}>{title}</h3>}
                        {subtitle && <p className="mt-0.5 text-sm text-gray-500">{subtitle}</p>}
                    </div>
                    {actions}
                </div>
            )}
            <div className={body}>{children}</div>
        </div>
    );
}