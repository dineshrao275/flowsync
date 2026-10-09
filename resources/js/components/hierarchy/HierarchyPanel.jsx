import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../ui/Alert';
import Card from '../ui/Card';
import Spinner from '../ui/Spinner';
import { taskUrl } from '../../utils/deepLinks';

function Node({ node, projectId, depth }) {
    const [open, setOpen] = useState(depth < 1);
    const hasChildren = node.children?.length > 0;
    const pct = node.progress?.total ? Math.round((node.progress.done / node.progress.total) * 100) : null;

    return (
        <li>
            <div className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50" style={{ paddingLeft: `${depth * 20 + 8}px` }}>
                <button
                    type="button"
                    aria-label={open ? 'Collapse' : 'Expand'}
                    disabled={!hasChildren}
                    onClick={() => setOpen((o) => !o)}
                    className="w-4 text-xs text-gray-400"
                >
                    {hasChildren ? (open ? '▾' : '▸') : ''}
                </button>
                {node.issue_type && <span className="h-2.5 w-2.5 rounded-sm" title={node.issue_type.name} style={{ backgroundColor: node.issue_type.color || '#94a3b8' }} />}
                {node.key ? (
                    <Link to={taskUrl(projectId, node.key)} className="font-mono text-xs text-gray-500 hover:underline">{node.key}</Link>
                ) : null}
                <span className={`min-w-0 flex-1 truncate ${node.status?.is_done ? 'text-gray-400 line-through' : 'text-gray-800'}`}>{node.title}</span>
                {node.status && <span className="text-xs text-gray-500">{node.status.name}</span>}
                {pct !== null && <span className="w-24 text-right text-xs text-gray-500">{node.progress.done}/{node.progress.total} · {pct}%</span>}
                <span className="hidden w-28 truncate text-right text-xs text-gray-500 sm:inline">{node.assignee?.name ?? ''}</span>
            </div>
            {open && hasChildren && (
                <ul>{node.children.map((c) => <Node key={c.id} node={c} projectId={projectId} depth={depth + 1} />)}</ul>
            )}
        </li>
    );
}

/** Initiative -> epic -> issue -> sub-task tree of one project (P4.1). */
export default function HierarchyPanel({ projectId }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        api.get(`/projects/${projectId}/hierarchy`)
            .then(({ data: d }) => active && setData(d))
            .catch((e) => active && setError(e.response?.data?.message || 'Could not load the hierarchy.'));
        return () => { active = false; };
    }, [projectId]);

    if (error) return <Alert>{error}</Alert>;
    if (!data) return <div className="flex justify-center py-10"><Spinner /></div>;

    return (
        <Card title="Hierarchy" subtitle="Initiatives, epics, issues and sub-tasks. Link an issue to its epic from the task editor.">
            {data.tree.length === 0 ? (
                <p className="text-sm text-gray-500">No tasks yet.</p>
            ) : (
                <ul className="space-y-0.5">{data.tree.map((n, i) => <Node key={n.id ?? `v${i}`} node={n} projectId={projectId} depth={0} />)}</ul>
            )}
            {data.truncated && <p className="mt-3 text-xs text-gray-500">Showing the first 2000 tasks.</p>}
        </Card>
    );
}
