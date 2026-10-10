import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import Spinner from '../components/ui/Spinner';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import { formatDateTime } from '../utils/format';

const STATUS_TONE = { delivered: 'text-emerald-700', retrying: 'text-amber-700', failed: 'text-red-700', pending: 'text-gray-500' };

/** Outbound webhooks: where the tenant's events are delivered, signed, with a delivery log. */
export default function Webhooks() {
    usePageTitle('Webhooks');
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [data, setData] = useState(null);
    const [form, setForm] = useState({ url: '', description: '', events: ['*'] });
    const [errors, setErrors] = useState({});
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [secret, setSecret] = useState(null);
    const [open, setOpen] = useState(null);
    const [deliveries, setDeliveries] = useState([]);

    useEffect(() => { setCrumbs([{ label: 'Webhooks' }]); }, [setCrumbs]);
    const load = useCallback(() => api.get('/webhooks').then(({ data: d }) => setData(d)).catch(() => setData({ endpoints: [], catalog: [] })), []);
    useEffect(() => { load(); }, [load]);

    function toggleEvent(type) {
        setForm((f) => {
            const has = f.events.includes(type);
            const events = type === '*' ? (has ? [] : ['*']) : has ? f.events.filter((e) => e !== type) : [...f.events.filter((e) => e !== '*'), type];
            return { ...f, events };
        });
    }

    async function create(e) {
        e.preventDefault();
        setSaving(true); setErrors({});
        try {
            const res = await api.post('/webhooks', form);
            setSecret({ id: res.data.endpoint.id, value: res.data.secret });
            setCreating(false); setForm({ url: '', description: '', events: ['*'] });
            load();
        } catch (err) { setErrors(fieldErrors(err)); } finally { setSaving(false); }
    }

    async function act(fn, ok) {
        try { const res = await fn(); if (ok) toast.success(ok); load(); return res; } catch (err) { toast.error(fieldErrors(err).form || err.response?.data?.message || 'Request failed.'); return null; }
    }

    async function showDeliveries(id) {
        if (open === id) { setOpen(null); return; }
        const { data: d } = await api.get(`/webhooks/${id}/deliveries`);
        setDeliveries(d.deliveries); setOpen(id);
    }

    async function test(id) {
        const res = await act(() => api.post(`/webhooks/${id}/test`));
        const d = res?.data?.delivery;
        if (d) d.status === 'delivered' ? toast.success(`Test delivered (HTTP ${d.response_status}).`) : toast.error(`Test failed: ${d.error || 'no response'}`);
        if (open === id) showDeliveries(id).then(() => showDeliveries(id));
    }

    if (!data) return <div className="flex justify-center py-20"><Spinner /></div>;

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Webhooks</h2>
                    <p className="mt-1 text-sm text-gray-500">Get a signed HTTP POST to your own system whenever something happens here.</p>
                </div>
                <Button onClick={() => setCreating((v) => !v)}>Add endpoint</Button>
            </div>

            {secret && (
                <Alert type="success">
                    <p className="font-semibold">Signing secret — copy it now, it is shown only once.</p>
                    <code className="mt-1 block break-all rounded bg-white dark:bg-[#161B26] dark:border-[#2F3A4C] p-2 text-xs">{secret.value}</code>
                    <p className="mt-2 text-xs">Verify each request: <code>X-FlowSync-Signature: t=&lt;unix&gt;,v1=&lt;HMAC-SHA256(secret, "&lt;t&gt;.&lt;raw body&gt;")&gt;</code>, and reject old timestamps.</p>
                    <button className="mt-2 text-xs underline" onClick={() => setSecret(null)}>I have saved it</button>
                </Alert>
            )}

            {creating && (
                <Card title="New endpoint" subtitle="https only; private and internal addresses are refused." className="border-gray-200 dark:border-[#2F3A4C]">
                    <form onSubmit={create} className="space-y-4">
                        <Input label="Endpoint URL" name="url" required placeholder="https://example.com/hooks/flowsync" value={form.url} onChange={(e) => setForm((f) => ({ ...f, url: e.target.value }))} error={errors.url} className="bg-white dark:bg-[#161B26] border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6]" />
                        <Input label="Description (optional)" name="description" value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} error={errors.description} className="bg-white dark:bg-[#161B26] border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6]" />
                        <div>
                            <p className="mb-1.5 text-sm font-medium text-gray-700">Events</p>
                            <label className="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.events.includes('*')} onChange={() => toggleEvent('*')} /> All events</label>
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                {data.catalog.map((t) => (
                                    <label key={t} className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" disabled={form.events.includes('*')} checked={form.events.includes('*') || form.events.includes(t)} onChange={() => toggleEvent(t)} className="rounded border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#161B26] text-gray-900 dark:text-[#F3F4F6]" /> {t}
                                    </label>
                                ))}
                            </div>
                            {errors.events && <p className="mt-1 text-sm text-red-600">{errors.events}</p>}
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
                            <Button type="submit" loading={saving}>Create endpoint</Button>
                        </div>
                    </form>
                </Card>
            )}

            {data.endpoints.length === 0 ? <p className="py-10 text-center text-sm text-gray-400">No endpoints yet.</p> : (
                <ul className="space-y-3">
                    {data.endpoints.map((e) => (
                        <li key={e.id} className="rounded-xl border border-gray-200 bg-white dark:bg-[#161B26] p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate font-mono text-sm text-gray-800 dark:text-gray-200">{e.url}</p>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">{e.description || 'No description'} · {e.events.join(', ')}</p>
                                    {e.disabled_reason && <p className="mt-1 text-xs text-red-600">{e.disabled_reason}</p>}
                                </div>
                                <Badge className="text-xs">{e.is_active ? 'active' : 'off'}</Badge>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2 text-sm">
                                <Button size="sm" variant="secondary" onClick={() => test(e.id)}>Send test</Button>
                                <Button size="sm" variant="secondary" onClick={() => showDeliveries(e.id)}>{open === e.id ? 'Hide log' : 'Delivery log'}</Button>
                                <Button size="sm" variant="secondary" onClick={() => act(() => api.put(`/webhooks/${e.id}`, { url: e.url, description: e.description, events: e.events, is_active: !e.is_active }), e.is_active ? 'Endpoint switched off.' : 'Endpoint switched on.')}>{e.is_active ? 'Switch off' : 'Switch on'}</Button>
                                <Button size="sm" variant="secondary" onClick={async () => { const r = await act(() => api.post(`/webhooks/${e.id}/rotate-secret`)); if (r) setSecret({ id: e.id, value: r.data.secret }); }}>Rotate secret</Button>
                                <Button size="sm" variant="danger" onClick={() => window.confirm('Delete this endpoint and its delivery log?') && act(() => api.delete(`/webhooks/${e.id}`), 'Endpoint deleted.')}>Delete</Button>
                            </div>
                            {open === e.id && (
                                <div className="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-[#2F3A4C]">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-gray-900 text-left uppercase text-gray-300 dark:bg-[#161B26]"><tr><th className="px-3 py-2">Event</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Tries</th><th className="px-3 py-2">HTTP</th><th className="px-3 py-2">When</th><th /></tr></thead>
                                        <tbody className="divide-y divide-gray-200 dark:divide-[#2F3A4C]">
                                            {deliveries.length === 0 && <tr><td colSpan={6} className="px-3 py-4 text-center text-gray-400">No deliveries yet.</td></tr>}
                                            {deliveries.map((d) => (
                                                <tr key={d.id} className="hover:bg-gray-50 dark:hover:bg-[#1C2433]">
                                                    <td className="px-3 py-2 font-mono text-gray-300 dark:text-gray-300">{d.event_type}</td>
                                                    <td className={`px-3 py-2 font-medium ${STATUS_TONE[d.status]}`}>{d.status}{d.error ? ` — ${d.error}` : ''}</td>
                                                    <td className="px-3 py-2 text-gray-300 dark:text-gray-300">{d.attempts}</td>
                                                    <td className="px-3 py-2 text-gray-300 dark:text-gray-300">{d.response_status ?? '—'}</td>
                                                    <td className="px-3 py-2 text-gray-300 dark:text-gray-300">{formatDateTime(d.created_at)}</td>
                                                    <td className="px-3 py-2 text-right"><button className="text-indigo-600 hover:underline" onClick={() => act(() => api.post(`/webhook-deliveries/${d.id}/redeliver`), 'Queued.')}>Redeliver</button></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
