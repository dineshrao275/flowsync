/**
 * Shared field styles — Figma fields are 10px-radius, 1px `#E3E7F0` hairline,
 * 13px DM Sans text with `#8A93A7` placeholders. `fieldClass` is the standard
 * white field; `fieldClassMuted` is the filled `#F8F9FC` variant used for
 * search/inline fields.
 */
export const fieldClass =
    'block w-full rounded-[10px] border border-[var(--border-hairline)] bg-[var(--card-bg)] px-3.5 py-2.5 text-[13px] text-ink placeholder-[var(--text-faint)] transition-colors duration-150 focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)]/50 disabled:cursor-not-allowed disabled:opacity-60';

export const fieldClassCompact =
    'block w-full rounded-[9px] border border-[var(--border-hairline)] bg-[var(--card-bg)] px-3 py-2 text-[12px] text-ink placeholder-[var(--text-faint)] transition-colors duration-150 focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)]/50 disabled:cursor-not-allowed disabled:opacity-60';

export const fieldClassMuted =
    'block w-full rounded-[10px] border border-transparent bg-[var(--field-bg)] px-3.5 py-2.5 text-[13px] text-ink placeholder-[var(--text-faint)] transition-colors duration-150 focus:border-[var(--accent)] focus:bg-[var(--card-bg)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)]/50';
