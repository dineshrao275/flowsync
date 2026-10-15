import { useCallback, useEffect, useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Card from '../ui/Card';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';

/**
 * Super Admin control of a tenant's two products: which plan each runs on and an
 * independent on/off switch per product. A tenant on the legacy bundle is moved to
 * per-product plans in one step so no product is ever left uncovered.
 */
export default function ProductsPanel({ tenantId, onChanged }) {
    const { t } = useTranslation();
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
            toast.error(fieldErrors(e).plan_id || fieldErrors(e).form || t('admin.productsPanel.saveFailed'));
        } finally { setBusy(false); }
    }

    if (data === null) return <Card title={t('admin.productsPanel.products')}><Spinner /></Card>;
    if (data === false) return <Card title={t('admin.productsPanel.products')}><p className="text-sm text-gray-400">{t('admin.productsPanel.unavailable')}</p></Card>;

    const subs = data.subscriptions || [];
    const bundle = subs.find((s) => s.product === 'suite' && s.status !== 'ended');
    const plansFor = (product) => (data.plans || []).filter((p) => p.product === product && p.is_active);
    const override = data.products?.override || {};
    const productName = (product) => t(`admin.subscription.products.${product}`, { defaultValue: product });

    function setSwitch(product, value) {
        const body = { tms: override.tms ?? null, hrms: override.hrms ?? null, [product]: value === 'plan' ? null : value === 'on' };
        call(() => api.put(`/tenants/${tenantId}/products`, body), t('admin.productsPanel.savedSwitch'));
    }

    return (
        <Card title={t('admin.productsPanel.productsPlans')} subtitle={t('admin.productsPanel.subtitle')}>
            {bundle && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <Trans i18nKey="admin.productsPanel.bundleNote" values={{ plan: bundle.plan?.name }} components={[<b key="0" />]} />
                    <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {['tms', 'hrms'].map((p) => (
                            <select key={p} value={pick[p]} onChange={(e) => setPick((x) => ({ ...x, [p]: e.target.value }))} className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                <option value="">{productName(p)} — {t('admin.productsPanel.none')}</option>
                                {plansFor(p).map((pl) => <option key={pl.id} value={pl.id}>{pl.name}</option>)}
                            </select>
                        ))}
                    </div>
                    <Button className="mt-2" size="sm" loading={busy} disabled={!pick.tms && !pick.hrms}
                        onClick={() => call(() => api.post(`/tenants/${tenantId}/subscription/split`, { tms_plan_id: pick.tms || null, hrms_plan_id: pick.hrms || null }), t('admin.productsPanel.savedSplit'))}>
                        {t('admin.productsPanel.moveToPerProduct')}
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
                                <p className="font-semibold text-gray-900">{productName(product)}</p>
                                <Badge>{data.products?.[product] ? t('admin.productsPanel.enabled') : t('admin.productsPanel.disabled')}</Badge>
                            </div>
                            <p className="mt-1 text-sm text-gray-600">
                                {sub ? <>{sub.plan?.name} · <span className="capitalize">{sub.status.replace('_', ' ')}</span></> : bundle ? t('admin.productsPanel.coveredBy', { plan: bundle.plan?.name }) : t('admin.productsPanel.noPlan')}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                {!bundle && (
                                    <>
                                        <select value={pick[product]} onChange={(e) => setPick((x) => ({ ...x, [product]: e.target.value }))} className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                            <option value="">{t('admin.productsPanel.assignPlan')}</option>
                                            {plansFor(product).map((pl) => <option key={pl.id} value={pl.id}>{pl.name}</option>)}
                                        </select>
                                        <Button size="sm" variant="secondary" disabled={!pick[product]} loading={busy}
                                            onClick={() => call(() => api.post(`/tenants/${tenantId}/subscription`, { plan_id: Number(pick[product]) }), t('admin.productsPanel.savedAssign'))}>
                                            {t('admin.productsPanel.assign')}
                                        </Button>
                                    </>
                                )}
                                <label className="ml-auto flex items-center gap-2 text-sm text-gray-600">
                                    {t('admin.productsPanel.switchLabel')}
                                    <select value={state} onChange={(e) => setSwitch(product, e.target.value)} className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                        <option value="plan">{t('admin.productsPanel.followPlan')}</option>
                                        <option value="on">{t('admin.productsPanel.forceOn')}</option>
                                        <option value="off">{t('admin.productsPanel.forceOff')}</option>
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
