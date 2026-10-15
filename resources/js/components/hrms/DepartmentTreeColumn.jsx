import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { orgUrl } from '../../utils/deepLinks';

/**
 * The org tree, with a headcount badge on every node.
 *
 * Two counts are printed per row and they are not the same number:
 * `headcount` is the whole subtree, `direct_count` the people sitting in *this*
 * department. Showing only the subtree figure is how a manager concludes a
 * 400-person company has one person, and showing only the direct figure is how
 * they conclude a team of 200 is a team of three — so the direct number is the
 * row and the subtree number is the muted parenthetical beside it.
 *
 * Ordering is by explicit up/down buttons rather than drag-and-drop. The reorder
 * endpoint names a whole sibling list, so a move is "this list, in this new
 * order" — which two buttons express exactly, with no drag state to get stuck
 * mid-gesture and no second implementation of sibling detection in JS to drift
 * from the server's. `dnd-kit` is already a dependency for the task board if
 * this is ever revisited.
 *
 * `canManage` hides the controls rather than disabling them, matching the rest
 * of the SPA: a control the caller is not allowed to use is a control that
 * should not be on the screen.
 *
 * Each node is a `Link` and nothing else drives the selection: the URL *is* the
 * selection, so a second `onClick` writing the same parameter would be the page
 * state and the router state disagreeing about one value.
 */
export default function DepartmentTreeColumn({ tree, departments, selectedId, canManage, onMove }) {
    const [collapsed, setCollapsed] = useState(() => new Set());

    // Everything open on first paint, so a tenant that has never touched a
    // collapse control sees the whole chart rather than a single root.
    useEffect(() => {
        setCollapsed(new Set());
    }, [tree]);

    function toggle(id) {
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }

    if (!tree || tree.length === 0) {
        return (
            <p className="px-1 py-6 text-sm text-gray-500">
                No departments yet. Create the first one to start the chart.
            </p>
        );
    }

    return (
        <ul className="space-y-0.5">
            {tree.map((node) => (
                <TreeNode
                    key={node.id}
                    node={node}
                    departments={departments}
                    collapsed={collapsed}
                    selectedId={selectedId}
                    canManage={canManage}
                    onToggle={toggle}
                    onMove={onMove}
                />
            ))}
        </ul>
    );
}

function TreeNode({ node, departments, collapsed, selectedId, canManage, onToggle, onMove }) {
    const hasChildren = node.departments.length > 0;
    const isCollapsed = collapsed.has(node.id);
    const selected = node.id === selectedId;

    // The siblings come from the flat list, which is the server's ordering
    // (`position`, then name). Reconstructing the sibling set from the tree
    // would mean walking the chart in JS to decide which two rows are adjacent.
    const siblings = departments
        .filter((d) => (d.parent_id ?? null) === (node.parent_id ?? null))
        .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
    const index = siblings.findIndex((d) => d.id === node.id);

    return (
        <li>
            <div
                className={`group flex items-center gap-1 rounded-lg px-1.5 py-1.5 transition-colors ${
                    selected ? 'bg-[var(--accent-soft)]' : 'hover:bg-gray-50'
                }`}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => onToggle(node.id)}
                        aria-label={isCollapsed ? `Expand ${node.name}` : `Collapse ${node.name}`}
                        aria-expanded={!isCollapsed}
                        className="flex h-5 w-5 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-200 hover:text-gray-700"
                    >
                        <span aria-hidden="true" className="text-xs leading-none">
                            {isCollapsed ? '▸' : '▾'}
                        </span>
                    </button>
                ) : (
                    <span className="h-5 w-5 shrink-0" aria-hidden="true" />
                )}

                {/* A link, not a button: the selection lives in the URL, so
                    `orgUrl` is the one place that knows how to build it and a
                    department can be shared, bookmarked and middle-clicked like
                    any other page in the SPA. */}
                <Link
                    to={orgUrl(node.id)}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left"
                >
                    <span
                        className={`truncate text-sm ${selected ? 'font-semibold' : 'font-medium'} ${
                            node.is_active ? 'text-gray-800' : 'text-gray-400 line-through'
                        }`}
                    >
                        {node.name}
                    </span>

                    <span className="shrink-0 text-xs text-gray-400" title="People in this department">
                        {node.direct_count}
                    </span>
                    {node.headcount !== node.direct_count && (
                        <span
                            className="shrink-0 text-xs text-gray-400"
                            title="People in this department and every department beneath it"
                        >
                            ({node.headcount})
                        </span>
                    )}
                </Link>

                {canManage && (
                    <span className="flex shrink-0 items-center opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                        <IconButton
                            label={`Move ${node.name} up`}
                            disabled={index <= 0}
                            onClick={() => onMove(node, -1)}
                        >
                            ↑
                        </IconButton>
                        <IconButton
                            label={`Move ${node.name} down`}
                            disabled={index === -1 || index === siblings.length - 1}
                            onClick={() => onMove(node, 1)}
                        >
                            ↓
                        </IconButton>
                    </span>
                )}
            </div>

            {hasChildren && !isCollapsed && (
                <ul className="ml-4 space-y-0.5 border-l border-gray-200 pl-2">
                    {node.departments.map((child) => (
                        <TreeNode
                            key={child.id}
                            node={child}
                            departments={departments}
                            collapsed={collapsed}
                            selectedId={selectedId}
                            canManage={canManage}
                            onToggle={onToggle}
                            onMove={onMove}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

function IconButton({ label, disabled, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className="flex h-6 w-6 items-center justify-center rounded text-xs text-gray-500 hover:bg-gray-200 hover:text-gray-800 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
        >
            {children}
        </button>
    );
}
