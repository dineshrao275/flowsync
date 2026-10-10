export default function Card({ title, subtitle, actions, children, className = '', dense = false }) {
    const body = dense ? 'px-5 py-4' : 'px-6 py-5';
    const header = dense ? 'px-5 py-3' : 'px-6 py-4';

    return (
        <div
            className={`rounded-xl border border-[var(--border-hairline)]/70 bg-white shadow-card transition-shadow duration-200 hover:shadow-popover ${className}`}
            style={{ backgroundColor: 'var(--card-bg)' }}
        >
            {(title || actions) && (
                <div className={`flex items-center justify-between gap-4 border-b border-[var(--border-hairline)] ${header}`}>
                    <div>
                        {title && <h3 className={`font-semibold text-[#1C1917] dark:text-[#F8FAFC] ${dense ? 'text-sm' : 'text-base'}`}>{title}</h3>}
                        {subtitle && <p className="mt-0.5 text-sm text-[#57534E] dark:text-[#94A3B8]">{subtitle}</p>}
                    </div>
                    {actions}
                </div>
            )}
            <div className={body}>{children}</div>
        </div>
    );
}