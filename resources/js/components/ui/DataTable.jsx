import { useTranslation } from 'react-i18next';
import Checkbox from './Checkbox';
import EmptyState from './EmptyState';
import ErrorState from './ErrorState';
import TableSkeleton from './TableSkeleton';
import { Table, Td, Th } from './Table';

/**
 * Data table with the cross-cutting behaviour every list page needs, so
 * pages stop re-implementing it:
 *  - loading skeleton, error + retry, and empty state slots
 *  - sortable headers (`aria-sort`, button in the header) — sorting is
 *    controlled: pass `sort={{ key, dir }}` and handle `onSort(key)`
 *  - row selection with select-all (`selected` is a Set of row keys)
 *  - below `sm` the rows render as stacked cards (first column = card title)
 *    instead of a sideways-scrolling table
 *
 * Columns: `{ key, header, render?(row), align?, sortable?, className?, hideOnMobile? }`.
 */
export default function DataTable({
    columns,
    rows = [],
    rowKey = (row) => row.id,
    loading = false,
    error = null,
    onRetry,
    empty,
    sort,
    onSort,
    selectable = false,
    selected,
    onSelectedChange,
    compact = false,
    caption,
}) {
    const { t } = useTranslation();

    if (loading) return <TableSkeleton columns={columns.length + (selectable ? 1 : 0)} />;
    if (error) return <ErrorState message={typeof error === 'string' ? error : undefined} onRetry={onRetry} />;
    if (rows.length === 0) return empty ?? <EmptyState title={t('admin.common.noResults')} />;

    const keys = rows.map(rowKey);
    const allSelected = selectable && keys.length > 0 && keys.every((key) => selected?.has(key));
    const someSelected = selectable && keys.some((key) => selected?.has(key));

    const toggleAll = () => onSelectedChange?.(allSelected ? new Set() : new Set(keys));
    const toggleRow = (key) => {
        const next = new Set(selected ?? []);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        onSelectedChange?.(next);
    };

    const cell = (column, row) => (column.render ? column.render(row) : row[column.key]);
    const [titleColumn, ...restColumns] = columns;

    return (
        <>
            <div className="hidden sm:block">
                <Table compact={compact}>
                    {caption && <caption className="sr-only">{caption}</caption>}
                    <thead>
                        <tr>
                            {selectable && (
                                <Th className="w-10">
                                    <input
                                        type="checkbox"
                                        aria-label={t('admin.common.selectAll')}
                                        checked={allSelected}
                                        ref={(node) => { if (node) node.indeterminate = someSelected && !allSelected; }}
                                        onChange={toggleAll}
                                        className="h-4 w-4 cursor-pointer accent-[var(--accent)]"
                                    />
                                </Th>
                            )}
                            {columns.map((column) => {
                                const active = sort?.key === column.key;

                                return (
                                    <Th
                                        key={column.key}
                                        align={column.align}
                                        aria-sort={column.sortable ? (active ? (sort.dir === 'desc' ? 'descending' : 'ascending') : 'none') : undefined}
                                        className={column.className}
                                    >
                                        {column.sortable ? (
                                            <button
                                                type="button"
                                                onClick={() => onSort?.(column.key)}
                                                className="inline-flex items-center gap-1 font-semibold hover:text-fg"
                                            >
                                                {column.header}
                                                <span aria-hidden="true" className={active ? 'text-fg' : 'text-fg-subtle'}>
                                                    {active ? (sort.dir === 'desc' ? '↓' : '↑') : '↕'}
                                                </span>
                                            </button>
                                        ) : column.header}
                                    </Th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const key = rowKey(row);

                            return (
                                <tr key={key} className={selected?.has(key) ? 'bg-[var(--accent-soft)]' : undefined}>
                                    {selectable && (
                                        <Td>
                                            <input
                                                type="checkbox"
                                                aria-label={t('admin.common.selectRow')}
                                                checked={selected?.has(key) ?? false}
                                                onChange={() => toggleRow(key)}
                                                className="h-4 w-4 cursor-pointer accent-[var(--accent)]"
                                            />
                                        </Td>
                                    )}
                                    {columns.map((column) => (
                                        <Td key={column.key} align={column.align} className={column.className}>
                                            {cell(column, row)}
                                        </Td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </Table>
            </div>

            <ul className="space-y-3 sm:hidden">
                {rows.map((row) => {
                    const key = rowKey(row);

                    return (
                        <li key={key} className="rounded-xl border border-line bg-surface p-4 shadow-card">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0 text-sm font-semibold text-fg">{cell(titleColumn, row)}</div>
                                {selectable && (
                                    <Checkbox
                                        aria-label={t('admin.common.selectRow')}
                                        checked={selected?.has(key) ?? false}
                                        onChange={() => toggleRow(key)}
                                    />
                                )}
                            </div>
                            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                {restColumns.filter((column) => !column.hideOnMobile).map((column) => (
                                    <div key={column.key} className="min-w-0">
                                        <dt className="text-caption text-fg-subtle">{column.header}</dt>
                                        <dd className="truncate text-fg">{cell(column, row)}</dd>
                                    </div>
                                ))}
                            </dl>
                        </li>
                    );
                })}
            </ul>
        </>
    );
}
