/**
 * Card — Figma surface panel: white, 12px radius, 1px `#E3E7F0` hairline,
 * subtle card shadow. Optional header (title/subtitle + actions).
 */
export default function Card({ title, subtitle, actions, children, className = '', dense = false, padded = true }) {
    const body = dense ? 'px-5 py-4' : 'px-6 py-5';
    const header = dense ? 'px-5 py-3.5' : 'px-6 py-4';

    return (
        <div
            className={`rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] shadow-[var(--shadow-card)] ${className}`}
        >
            {(title || subtitle || actions) && (
                <div className={`flex items-center justify-between gap-4 border-b border-[var(--border-hairline)] ${header}`}>
                    <div className="min-w-0">
                        {title && <h3 className={`font-semibold text-ink ${dense ? 'text-[13px]' : 'text-[15px]'}`}>{title}</h3>}
                        {subtitle && <p className="mt-0.5 text-[12px] text-muted">{subtitle}</p>}
                    </div>
                    {actions}
                </div>
            )}
            {padded ? <div className={body}>{children}</div> : children}
        </div>
    );
}
