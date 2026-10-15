import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Card from '../ui/Card';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';

const key = (from, to) => `${from ?? 'any'}>${to}`;

/** Allowed status transitions (an optional allow-list) and what a task needs to enter each status. */
export default function WorkflowRules({ projectId, onSaved }) {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [enforce, setEnforce] = useState(false);
    const [allowed, setAllowed] = useState(new Set());
    const [rules, setRules] = useState({});
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const load = useCallback(() => api.get(`/projects/${projectId}/workflow`).then(({ data: d }) => {
        setData(d);
        setEnforce(d.enforce_workflow);
        setAllowed(new Set(d.transitions.map((t) => key(t.from_status_id, t.to_status_id))));
        setRules(Object.fromEntries(d.statuses.map((s) => [s.id, s.entry_rules || []])));
    }), [projectId]);
    useEffect(() => { load(); }, [load]);

    if (!data) return <Card title="Workflow rules"><Spinner /></Card>;

    const toggle = (from, to) => setAllowed((cur) => { const next = new Set(cur); const k = key(from, to); next.has(k) ? next.delete(k) : next.add(k); return next; });
    const toggleRule = (statusId, rule) => setRules((cur) => ({ ...cur, [statusId]: cur[statusId].includes(rule) ? cur[statusId].filter((r) => r !== rule) : [...cur[statusId], rule] }));

    async function save() {
        setSaving(true); setError(null);
        try {
            await api.put(`/projects/${projectId}/workflow`, {
                enforce_workflow: enforce,
                transitions: [...allowed].map((k) => { const [f, t] = k.split('>'); return { from_status_id: f === 'any' ? null : Number(f), to_status_id: Number(t) }; }),
                entry_rules: rules,
            });
            toast.success('Workflow saved.');
            onSaved?.();
            load();
        } catch (e) { setError(fieldErrors(e).transitions || fieldErrors(e).form || 'Could not save the workflow.'); } finally { setSaving(false); }
    }

    return (
        <Card title="Workflow rules" subtitle="Optionally restrict which status a task can move to, and what it needs before entering one.">
            <label className="mb-4 flex items-center gap-2 text-sm font-medium text-gray-800">
                <input type="checkbox" checked={enforce} onChange={(e) => setEnforce(e.target.checked)} />
                Enforce this workflow (when off, any status can move to any status)
            </label>

            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th className="px-2 py-1.5">From ↓ / To →</th>
                            {data.statuses.map((s) => <th key={s.id} className="px-2 py-1.5">{s.name}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {[{ id: null, name: 'Any status' }, ...data.statuses].map((from) => (
                            <tr key={from.id ?? 'any'} className="border-t border-gray-100">
                                <td className="px-2 py-1.5 font-medium text-gray-800">{from.name}</td>
                                {data.statuses.map((to) => (
                                    <td key={to.id} className="px-2 py-1.5 text-center">
                                        {from.id === to.id ? <span className="text-gray-300">—</span> : (
                                            <input type="checkbox" checked={allowed.has(key(from.id, to.id))} onChange={() => toggle(from.id, to.id)} />
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <h4 className="mb-2 mt-5 text-sm font-semibold text-gray-900">Entry requirements</h4>
            <div className="space-y-2">
                {data.statuses.map((s) => (
                    <div key={s.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span className="w-32 font-medium text-gray-800">{s.name}</span>
                        {Object.entries(data.entry_rule_catalog).map(([rule, label]) => (
                            <label key={rule} className="flex items-center gap-1.5 text-gray-600">
                                <input type="checkbox" checked={(rules[s.id] || []).includes(rule)} onChange={() => toggleRule(s.id, rule)} /> needs {label}
                            </label>
                        ))}
                    </div>
                ))}
            </div>

            <Alert>{error}</Alert>
            <div className="mt-4"><Button onClick={save} loading={saving}>Save workflow</Button></div>
        </Card>
    );
}
