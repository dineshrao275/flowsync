import { TONES } from './Badge';

const DOTS = {
    neutral: 'bg-fg-subtle',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger-fg',
    info: 'bg-info',
    accent: 'bg-[var(--accent)]',
};

/**
 * One status pill for every surface: a hue dot on a tinted chip with dark
 * text. The text is deliberately NOT the hue — 12px coloured text on its own
 * tint fails contrast almost everywhere — while the dot and the tint keep
 * the colour coding.
 *
 * Prefer `tone` (success | warning | danger | info | accent | neutral): it
 * reads the semantic tokens and is theme/dark safe. `color` (a hex) stays for
 * user-defined statuses (task statuses, labels) whose hue is data.
 */
export default function StatusPill({ color, tone, label, className = '' }) {
    if (tone && TONES[tone]) {
        return (
            <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize ${TONES[tone]} ${className}`}>
                <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${DOTS[tone]}`} aria-hidden="true" />
                <span>{label}</span>
            </span>
        );
    }

    const hue = color || '#6b7280';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize ${className}`}
            style={{ backgroundColor: `${hue}22` }}
        >
            <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: hue }} aria-hidden="true" />
            <span className="text-gray-800">{label}</span>
        </span>
    );
}
