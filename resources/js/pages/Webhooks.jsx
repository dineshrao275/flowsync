import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import Spinner from '../components/ui/Spinner';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import Timestamp from '../components/ui/Timestamp';

const STATUS_TONE = { delivered: 'text-emerald-700', retrying: 'text-amber-700', failed: 'text-red-700', pending: 'text-gray-500' };

/** Outbound webhooks: where the tenant's events are delivered, signed, with a delivery log. */
export default function Webhooks() {
    const { t } = useTranslation();
    usePageTitle(t('admin.webhooks.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
    const setCrumbs = useSetCrumbs();
    const [data, setData] = useState(null);
    const [form, setForm] = useState({ url: '', description: '', events: ['*'] });
    const [errors, setErrors] = useState({});
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [secret, setSecret] = useState(null);
    const [open, setOpen] = useState(null);
    const [deliveries, setDeliveries] = useState([]);

    useEffect(() => { setCrumbs([{ label: t('admin.webhooks.pageTitle') }]); }, [setCrumbs, t]);
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
        try { const res = await fn(); if (ok) toast.success(ok); load(); return res; } catch (err) { toast.error(fieldErrors(err).form || err.response?.data?.message || t('admin.webhooks.requestFailed')); return null; }
    }

    async function showDeliveries(id) {
        if (open === id) { setOpen(null); return; }
        const { data: d } = await api.get(`/webhooks/${id}/deliveries`);
        setDeliveries(d.deliveries); setOpen(id);
    }

    async function test(id) {
        const res = await act(() => api.post(`/webhooks/${id}/test`));
        const d = res?.data?.delivery;
        if (d) d.status === 'delivered' ? toast.success(t('admin.webhooks.testDelivered', { status: d.response_status })) : toast.error(t('admin.webhooks.testFailed', { error: d.error || t('admin.webhooks.noResponse') }));
        if (open === id) showDeliveries(id).then(() => showDeliveries(id));
    }

    if (!data) return <div className="flex justify-center py-20"><Spinner /></div>;

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.webhooks.title')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('admin.webhooks.subtitle')}</p>
                </div>
                <Button onClick={() => setCreating((v) => !v)}>{t('admin.webhooks.addEndpoint')}</Button>
            </div>

            {secret && (
                <Alert type="success">
                    <p className="font-semibold">{t('admin.webhooks.secretTitle')}</p>
                    <code className="mt-1 block break-all rounded bg-white/70 p-2 text-xs">{secret.value}</code>
                    <p className="mt-2 text-xs">{t('admin.webhooks.secretVerifyPrefix')} <code>X-FlowSync-Signature: t=&lt;unix&gt;,v1=&lt;HMAC-SHA256(secret, "&lt;t&gt;.&lt;raw body&gt;")&gt;</code>{t('admin.webhooks.secretVerifySuffix')}</p>
                    <button className="mt-2 text-xs underline" onClick={() => setSecret(null)}>{t('admin.webhooks.savedSecret')}</button>
                </Alert>
            )}

            {creating && (
                <Card title={t('admin.webhooks.newEndpointTitle')} subtitle={t('admin.webhooks.newEndpointSubtitle')}>
                    <form onSubmit={create} className="space-y-4">
                        <Input label={t('admin.webhooks.endpointUrl')} name="url" required placeholder="https://example.com/hooks/flowsync" value={form.url} onChange={(e) => setForm((f) => ({ ...f, url: e.target.value }))} error={errors.url} />
                        <Input label={t('admin.webhooks.descriptionOptional')} name="description" value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} error={errors.description} />
                        <div>
                            <p className="mb-1.5 text-sm font-medium text-gray-700">{t('admin.webhooks.events')}</p>
                            <label className="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.events.includes('*')} onChange={() => toggleEvent('*')} /> {t('admin.webhooks.allEvents')}</label>
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                {data.catalog.map((type) => (
                                    <label key={type} className="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" disabled={form.events.includes('*')} checked={form.events.includes('*') || form.events.includes(type)} onChange={() => toggleEvent(type)} /> {type}
                                    </label>
                                ))}
                            </div>
                            {errors.events && <p className="mt-1 text-sm text-red-600">{errors.events}</p>}
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>{t('admin.common.cancel')}</Button>
                            <Button type="submit" loading={saving}>{t('admin.webhooks.createEndpoint')}</Button>
                        </div>
                    </form>
                </Card>
            )}

            {data.endpoints.length === 0 ? <p className="py-10 text-center text-sm text-gray-400">{t('admin.webhooks.empty')}</p> : (
                <ul className="space-y-3">
                    {data.endpoints.map((e) => (
                        <li key={e.id} className="rounded-xl border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="truncate font-mono text-sm text-gray-900">{e.url}</p>
                                    <p className="text-xs text-gray-500">{e.description || t('admin.webhooks.noDescription')} · {e.events.join(', ')}</p>
                                    {e.disabled_reason && <p className="mt-1 text-xs text-red-600">{e.disabled_reason}</p>}
                                </div>
                                <Badge>{e.is_active ? t('admin.webhooks.active') : t('admin.webhooks.off')}</Badge>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2 text-sm">
                                <Button size="sm" variant="secondary" onClick={() => test(e.id)}>{t('admin.webhooks.sendTest')}</Button>
                                <Button size="sm" variant="secondary" onClick={() => showDeliveries(e.id)}>{open === e.id ? t('admin.webhooks.hideLog') : t('admin.webhooks.deliveryLog')}</Button>
                                <Button size="sm" variant="secondary" onClick={() => act(() => api.put(`/webhooks/${e.id}`, { url: e.url, description: e.description, events: e.events, is_active: !e.is_active }), e.is_active ? t('admin.webhooks.endpointOff') : t('admin.webhooks.endpointOn'))}>{e.is_active ? t('admin.webhooks.switchOff') : t('admin.webhooks.switchOn')}</Button>
                                <Button size="sm" variant="secondary" onClick={async () => { const r = await act(() => api.post(`/webhooks/${e.id}/rotate-secret`)); if (r) setSecret({ id: e.id, value: r.data.secret }); }}>{t('admin.webhooks.rotateSecret')}</Button>
                                <Button size="sm" variant="danger" onClick={async () => { if (await confirm({ title: t('admin.webhooks.deleteTitle'), message: t('admin.webhooks.deleteMessage'), variant: 'danger' })) act(() => api.delete(`/webhooks/${e.id}`), t('admin.webhooks.endpointDeleted')); }}>{t('admin.common.delete')}</Button>
                            </div>
                            {open === e.id && (
                                <div className="mt-3 overflow-x-auto rounded-lg border border-gray-100">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-gray-50 text-left uppercase text-gray-500"><tr><th className="px-3 py-2">{t('admin.webhooks.thEvent')}</th><th className="px-3 py-2">{t('admin.webhooks.thStatus')}</th><th className="px-3 py-2">{t('admin.webhooks.thTries')}</th><th className="px-3 py-2">{t('admin.webhooks.thHttp')}</th><th className="px-3 py-2">{t('admin.webhooks.thWhen')}</th><th /></tr></thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {deliveries.length === 0 && <tr><td colSpan={6} className="px-3 py-4 text-center text-gray-400">{t('admin.webhooks.noDeliveries')}</td></tr>}
                                            {deliveries.map((d) => (
                                                <tr key={d.id}>
                                                    <td className="px-3 py-2 font-mono">{d.event_type}</td>
                                                    <td className={`px-3 py-2 font-medium ${STATUS_TONE[d.status]}`}>{d.status}{d.error ? ` — ${d.error}` : ''}</td>
                                                    <td className="px-3 py-2">{d.attempts}</td>
                                                    <td className="px-3 py-2">{d.response_status ?? '—'}</td>
                                                    <td className="px-3 py-2"><Timestamp date={d.created_at} /></td>
                                                    <td className="px-3 py-2 text-right"><button className="text-[var(--accent-soft-text)] hover:underline" onClick={() => act(() => api.post(`/webhook-deliveries/${d.id}/redeliver`), t('admin.webhooks.queued'))}>{t('admin.webhooks.redeliver')}</button></td>
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
