import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Card from '../ui/Card';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';

const NAMES = { tms: 'Task Management (TMS)', hrms: 'HR Management (HRMS)' };

/**
 * Super Admin control of a tenant's two products: which plan each runs on and an
 * independent on/off switch per product. A tenant on the legacy bundle is moved to
 * per-product plans in one step so no product is ever left uncovered.
 */
export default function ProductsPanel({ tenantId, onChanged }) {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [busy, setBusy] = useState(false);
    const [pick, setPick] = useState({ tms: '', hrms: '' });

    const load = useCallback(() => {
        api.get(`/tenants/${tenantId}/subscription`).then(({ data: d }) => setData(d)).catch(() => setData(false));
    }, [tenantId]);
    useEffect(() => { load(); }, [load]);

    async function call(fn, ok) {
        setBusy(true);
        try {
            const { data: d } = await fn();
            setData((cur) => ({ ...cur, ...d }));
            toast.success(ok);
            onChanged?.();
        } catch (e) {
            toast.error(fieldErrors(e).plan_id || fieldErrors(e).form || 'Could not save.');
        } finally { setBusy(false); }
    }

    if (data === null) return <Card title="Products"><Spinner /></Card>;
    if (data === false) return <Card title="Products"><p className="text-sm text-gray-400">Unavailable.</p></Card>;

    const subs = data.subscriptions || [];
    const bundle = subs.find((s) => s.product === 'suite' && s.status !== 'ended');
    const plansFor = (product) => (data.plans || []).filter((p) => p.product === product && p.is_active);
    const override = data.products?.override || {};

    function setSwitch(product, value) {
        const body = { tms: override.tms ?? null, hrms: override.hrms ?? null, [product]: value === 'plan' ? null : value === 'on' };
        call(() => api.put(`/tenants/${tenantId}/products`, body), 'Product switch saved.');
    }

    return (
        <Card title="Products & plans" subtitle="Each product has its own plan and its own on/off switch.">
            {bundle && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    On the bundle plan <b>{bundle.plan?.name}</b>. Pick a plan for each product to move to per-product billing.
                    <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {['tms', 'hrms'].map((p) => (
                            <select key={p} value={pick[p]} onChange={(e) => setPick((x) => ({ ...x, [p]: e.target.value }))} className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                <option value="">{NAMES[p]} — none</option>
                                {plansFor(p).map((pl) => <option key={pl.id} value={pl.id}>{pl.name}</option>)}
                            </select>
                        ))}
                    </div>
                    <Button className="mt-2" size="sm" loading={busy} disabled={!pick.tms && !pick.hrms}
                        onClick={() => call(() => api.post(`/tenants/${tenantId}/subscription/split`, { tms_plan_id: pick.tms || null, hrms_plan_id: pick.hrms || null }), 'Moved to per-product plans.')}>
                        Move to per-product plans
                    </Button>
                </div>
            )}
            <div className="space-y-4">
                {['tms', 'hrms'].map((product) => {
                    const sub = subs.find((s) => s.product === product && s.status !== 'ended');
                    const state = override[product] === true ? 'on' : override[product] === false ? 'off' : 'plan';
                    return (
                        <div key={product} className="rounded-lg border border-gray-200 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="font-semibold text-gray-900">{NAMES[product]}</p>
                                <Badge>{data.products?.[product] ? 'enabled' : 'disabled'}</Badge>
                            </div>
                            <p className="mt-1 text-sm text-gray-600">
                                {sub ? <>{sub.plan?.name} · <span className="capitalize">{sub.status.replace('_', ' ')}</span></> : bundle ? `Covered by ${bundle.plan?.name}` : 'No plan'}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                {!bundle && (
                                    <>
                                        <select value={pick[product]} onChange={(e) => setPick((x) => ({ ...x, [product]: e.target.value }))} className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                            <option value="">Assign a plan…</option>
                                            {plansFor(product).map((pl) => <option key={pl.id} value={pl.id}>{pl.name}</option>)}
                                        </select>
                                        <Button size="sm" variant="secondary" disabled={!pick[product]} loading={busy}
                                            onClick={() => call(() => api.post(`/tenants/${tenantId}/subscription`, { plan_id: Number(pick[product]) }), 'Plan assigned.')}>
                                            Assign
                                        </Button>
                                    </>
                                )}
                                <label className="ml-auto flex items-center gap-2 text-sm text-gray-600">
                                    Switch
                                    <select value={state} onChange={(e) => setSwitch(product, e.target.value)} className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                        <option value="plan">Follow plan</option>
                                        <option value="on">Force on</option>
                                        <option value="off">Force off</option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}
