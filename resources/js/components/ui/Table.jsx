import { createContext, useContext } from 'react';

const TableStyleContext = createContext({ compact: false, sticky: false });

/**
 * Table primitives for list pages. `Table` renders the panel + scroll container
 * (replacing the old per-entity card grids), `Th`/`Td` keep column markup
 * consistent, and `TableEmpty` renders the shared empty row.
 *
 * `compact` tightens cell padding for dense rosters and ledgers; `sticky`
 * (default on) pins the header row while a long table scrolls underneath.
 */
export function Table({ children, className = '', containerClassName = '', compact = false, sticky = true }) {
    return (
        <div
            className={`overflow-hidden rounded-xl border border-gray-200/70 bg-white shadow-card ${containerClassName}`}
            style={{ backgroundColor: 'var(--card-bg)' }}
        >
            <div className="overflow-x-auto">
                <TableStyleContext.Provider value={{ compact, sticky }}>
                    <table className={`w-full text-left text-sm ${className}`}>{children}</table>
                </TableStyleContext.Provider>
            </div>
        </div>
    );
}

export function Th({ children, className = '', align = 'left', ...rest }) {
    const alignment = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';
    const { compact, sticky } = useContext(TableStyleContext);

    return (
        <th
            scope="col"
            {...rest}
            className={`whitespace-nowrap border-b border-gray-200 text-xs font-semibold normal-case tracking-normal text-gray-500 ${alignment} ${
                compact ? 'px-3 py-1.5' : 'px-4 py-2.5'
            } ${sticky ? 'sticky top-0 z-10 bg-gray-50' : 'bg-gray-50/60'} ${className}`}
        >
            {children}
        </th>
    );
}

export function Td({ children, className = '', align = 'left', ...rest }) {
    const alignment = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';
    const { compact } = useContext(TableStyleContext);

    return (
        <td {...rest} className={`border-b border-gray-100 align-middle text-gray-700 ${alignment} ${compact ? 'px-3 py-1.5' : 'px-4 py-2.5'} ${className}`}>
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
