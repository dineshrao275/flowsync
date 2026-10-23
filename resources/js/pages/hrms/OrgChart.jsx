import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import Resource from '../../components/ui/Resource';
import { useResource } from '../../hooks/useResource';
import Avatar from '../../components/ui/Avatar';

function Node({ node, byParent, open, toggle, match, depth }) {
    const kids = byParent.get(node.id) || [];
    const isOpen = open.has(node.id) || depth < 1;
    const dim = match && !match(node);

    return (
        <li className="relative pl-6 before:absolute before:left-2 before:top-0 before:h-full before:border-l before:border-gray-200 last:before:h-5">
            <span className="absolute left-2 top-5 w-4 border-t border-gray-200" aria-hidden="true" />
            <div className={`my-1.5 inline-flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 shadow-sm ${dim ? 'opacity-40' : ''}`}>
                <Avatar name={node.name} size="sm" />
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-gray-900">{node.name}</p>
                    <p className="truncate text-xs text-gray-500">{[node.title, node.department].filter(Boolean).join(' · ')}</p>
                </div>
                {kids.length > 0 && (
                    <button type="button" className="rounded-md px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-100" aria-expanded={isOpen} onClick={() => toggle(node.id)}>
                        {isOpen ? '−' : '+'}{kids.length}
                    </button>
                )}
            </div>
            {isOpen && kids.length > 0 && (
                <ul>{kids.map((k) => <Node key={k.id} node={k} byParent={byParent} open={open} toggle={toggle} match={match} depth={depth + 1} />)}</ul>
            )}
        </li>
    );
}

/** The reporting-line chart: who reports to whom, collapsible, with a name filter. */
export default function OrgChart() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.orgChart.pageTitle'));
    const res = useResource((signal) => api.get('/hrms/org-chart', { signal }).then(({ data }) => data), []);

    return (
        <Resource {...res} skeleton={<ContentSkeleton size="lg" />} errorTitle={t('hrms.orgChart.loadFailed')}>
            {(data) => <OrgChartBody data={data} />}
        </Resource>
    );
}

function OrgChartBody({ data }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(new Set());
    const [q, setQ] = useState('');

    const { roots, byParent } = useMemo(() => {
        const map = new Map();
        (data?.nodes || []).forEach((n) => {
            const key = n.manager_id ?? 0;
            map.set(key, [...(map.get(key) || []), n]);
        });

        return { roots: map.get(0) || [], byParent: map };
    }, [data]);

    const needle = q.trim().toLowerCase();
    const match = needle ? (n) => `${n.name} ${n.title || ''} ${n.department || ''}`.toLowerCase().includes(needle) : null;

    function toggle(id) {
        setOpen((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);

            return next;
        });
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('hrms.orgChart.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('hrms.orgChart.subtitle')}</p>
                </div>
                <div className="w-64"><Input label={t('hrms.orgChart.find')} value={q} onChange={(e) => setQ(e.target.value)} compact /></div>
            </div>
            {data.truncated && <Alert>{t('hrms.orgChart.truncated')}</Alert>}
            <Card>
                {roots.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.orgChart.empty')}</p> : (
                    <ul className="overflow-x-auto">
                        {roots.map((r) => <Node key={r.id} node={r} byParent={byParent} open={needle ? new Set(data.nodes.map((n) => n.id)) : open} toggle={toggle} match={match} depth={0} />)}
                    </ul>
                )}
            </Card>
        </div>
    );
}
