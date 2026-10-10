/**
 * Table primitives for list pages. `Table` renders the panel + scroll container
 * (replacing the old per-entity card grids), `Th`/`Td` keep column markup
 * consistent, and `TableEmpty` renders the shared empty row.
 */
export function Table({ children, className = '', containerClassName = '' }) {
    return (
        <div
            className={`overflow-hidden rounded-xl border border-[var(--border-hairline)]/70 bg-white shadow-card ${containerClassName} dark:border-[#2F3A4C]`}
            style={{ backgroundColor: 'var(--card-bg)' }}
        >
            <div className="overflow-x-auto">
                <table className={`w-full text-left text-sm ${className}`}>{children}</table>
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
            className={`whitespace-nowrap border-b border-[var(--border-hairline)] bg-[var(--surface-elevated)] px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-[#57534E] dark:text-[#94A3B8] ${alignment} ${className}`}
        >
            {children}
        </th>
    );
}

export function Td({ children, className = '', align = 'left', ...rest }) {
    const alignment = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return (
        <td {...rest} className={`border-b border-[var(--border-hairline)] px-4 py-2.5 align-middle text-[#1C1917] dark:text-[#F8FAFC] ${alignment} ${className}`}>
            {children}
        </td>
    );
}

export function TableEmpty({ colSpan, children }) {
    return (
        <tr>
            <Td colSpan={colSpan} className="py-10 text-center text-sm text-gray-400">
                {children}
            </Td>
        </tr>
    );
}
