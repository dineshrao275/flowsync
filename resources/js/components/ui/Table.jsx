/**
 * Table primitives for list pages. `Table` renders the panel + scroll container,
 * `Th`/`Td` keep column markup consistent, and `TableEmpty` renders the shared
 * empty row. Header sits on the `#F8F9FC` muted surface with a hairline rule.
 */
export function Table({ children, className = '', containerClassName = '' }) {
    return (
        <div className={`overflow-hidden rounded-[12px] border border-[var(--border-hairline)] bg-[var(--card-bg)] shadow-[var(--shadow-card)] ${containerClassName}`}>
            <div className="overflow-x-auto">
                <table className={`w-full text-left ${className}`}>{children}</table>
            </div>
        </div>
    );
}

export function Th({ children, className = '', align = 'left', ...rest }) {
    const alignment = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return (
        <th
            scope="col"
            {...rest}
            className={`whitespace-nowrap border-b border-[var(--border-hairline)] bg-[var(--surface-elevated)] px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.04em] text-muted ${alignment} ${className}`}
        >
            {children}
        </th>
    );
}

export function Td({ children, className = '', align = 'left', ...rest }) {
    const alignment = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return (
        <td {...rest} className={`border-b border-[var(--border-hairline)] px-4 py-3 align-middle text-[13px] text-ink ${alignment} ${className}`}>
            {children}
        </td>
    );
}

export function TableEmpty({ colSpan, children }) {
    return (
        <tr>
            <Td colSpan={colSpan} className="py-10 text-center text-[13px] text-[var(--text-faint)]">
                {children}
            </Td>
        </tr>
    );
}
