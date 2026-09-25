/**
 * Table primitives for list pages. `Table` renders the panel + scroll container
 * (replacing the old per-entity card grids), `Th`/`Td` keep column markup
 * consistent, and `TableEmpty` renders the shared empty row.
 */
export function Table({ children, className = '', containerClassName = '' }) {
    return (
        <div
            className={`overflow-hidden rounded-xl border border-gray-200/70 bg-white shadow-card ${containerClassName}`}
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
            {...rest}
            className={`whitespace-nowrap border-b border-gray-200 bg-gray-50/60 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-gray-500 ${alignment} ${className}`}
        >
            {children}
        </th>
    );
}

export function Td({ children, className = '', align = 'left', ...rest }) {
    const alignment = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';

    return (
        <td {...rest} className={`border-b border-gray-100 px-4 py-2.5 align-middle text-gray-700 ${alignment} ${className}`}>
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
