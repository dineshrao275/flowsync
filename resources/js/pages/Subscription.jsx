import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { formatDate, formatPrice } from '../utils/format';
import Timestamp from '../components/ui/Timestamp';

const RESOURCES = ['users', 'seats', 'workspaces', 'projects', 'tasks'];

const MODULE_KEYS = { 'export.full': 'exportFull' };

function moduleLabel(t, m) {
    return t(`admin.subscription.modules.${MODULE_KEYS[m] || m}`, { defaultValue: m });
}

function statusLabel(t, status) {
    return status ? t(`admin.subscription.statuses.${status}`, { defaultValue: status }) : status;
}

function productName(t, product) {
    return t(`admin.subscription.products.${product}`, { defaultValue: product });
}

function daysRemaining(iso) {
    if (!iso) return null;
    return Math.max(0, Math.ceil((new Date(iso) - new Date()) / 86400000));
}

function ModuleList({ title, modules, tone }) {
    const { t } = useTranslation();
    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">{title}</p>
            {modules.length === 0 ? (
                <p className="mt-2 text-sm text-gray-500">{t('admin.subscription.none')}</p>
            ) : (
                <ul className="mt-2 space-y-1.5">
                    {modules.map((m) => (
                        <li
                            key={m}
                            className={`flex items-center gap-2 text-sm ${
                                tone === 'included' ? 'font-medium text-emerald-700' : 'text-gray-500'
                            }`}
                        >
                            {tone === 'included' ? (
                                <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M20 6L9 17l-5-5" />
                                </svg>
                            ) : (
                                <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M18 6L6 18M6 6l12 12" />
                                </svg>
                            )}
                            {moduleLabel(t, m)}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Subscription() {
    const { t } = useTranslation();
    usePageTitle(t('admin.subscription.pageTitle'));
    const toast = useToast();
    const confirm = useConfirm();
    const { user, can } = useAuth();
    const [subscription, setSubscription] = useState(null);
    const [tenant, setTenant] = useState(null);
    const [events, setEvents] = useState([]);
    const [usage, setUsage] = useState({});
    const [limits, setLimits] = useState({});
    const [modules, setModules] = useState(null);
    const [modulesAvailable, setModulesAvailable] = useState([]);
    const [plans, setPlans] = useState([]);
    const [subscriptions, setSubscriptions] = useState([]);
    const [payments, setPayments] = useState([]);
    const [invoices, setInvoices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [action, setAction] = useState(null);

    // Mirrors MySubscriptionController::authorizeAdmin exactly: the admin ROLE
    // OR the `billing.manage` grant. Keying off the role alone hid every
    // cancel/renew/switch control from a user an admin granted `billing.manage`
    // — the server would have taken the write, the page said "ask an admin".
    const canManage = user?.roles?.includes('admin') || can('billing.manage');

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [subRes, usageRes, plansRes, historyRes, invoiceRes] = await Promise.all([
                api.get('/my-subscription'),
                api.get('/my-usage'),
                api.get('/plans'),
                api.get('/billing/history').catch(() => ({ data: { payments: [] } })),
                api.get('/billing/invoices').catch(() => ({ data: { invoices: [] } })),
            ]);
            setSubscription(subRes.data.subscription);
            setSubscriptions(subRes.data.subscriptions || []);
            setTenant(subRes.data.tenant);
            setEvents(subRes.data.events || []);
            setUsage(usageRes.data.usage || {});
            setLimits(usageRes.data.limits || {});
            setModules(usageRes.data.modules ?? null);
            setModulesAvailable(usageRes.data.modules_available || []);
            setPlans(plansRes.data.plans || []);
            setPayments(historyRes.data.payments || []);
            setInvoices(invoiceRes.data.invoices || []);
        } catch (e) {
            setError(e.response?.status === 404 ? t('admin.subscription.loadNoContext') : t('admin.subscription.loadFailed'));
        } finally {
            setLoading(false);
        }
    }, [t]);

    useEffect(() => {
        load();
    }, [load]);

    // Stripe sends the customer back here with ?session_id=…&payment_id=…&status=…
    // Nothing is paid until the server has checked that session with Stripe.
    const [searchParams, setSearchParams] = useSearchParams();
    const handledReturn = useRef(false);
    useEffect(() => {
        const status = searchParams.get('status');
        if (!status || handledReturn.current) return;
        handledReturn.current = true;

        const sessionId = searchParams.get('session_id');
        const paymentId = searchParams.get('payment_id');
        const clean = () => setSearchParams({}, { replace: true });

        if (status === 'canceled') {
            toast.info(t('admin.subscription.checkoutCanceled'));
            clean();
            return;
        }
        if (status === 'success' && sessionId && paymentId) {
            api.post('/billing/verify', { payment_id: Number(paymentId), provider_payment_id: sessionId })
                .then(() => {
                    toast.success(t('admin.subscription.paymentConfirmed'));
                    return load();
                })
                .catch((e) => toast.error(fieldErrors(e).form || e.response?.data?.message || t('admin.subscription.paymentUnconfirmed')))
                .finally(clean);
        } else {
            clean();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchParams]);

    async function openPortal() {
        setAction(true);
        try {
            const { data } = await api.post('/billing/portal');
            window.location.href = data.url;
        } catch (e) {
            toast.error(fieldErrors(e).form || e.response?.data?.message || t('admin.subscription.portalUnavailable'));
            setAction(false);
        }
    }

    async function run(fn, successMessage) {
        setAction(true);
        try {
            await fn();
            toast.success(successMessage);
            await load();
        } catch (e) {
            toast.error(fieldErrors(e).form || fieldErrors(e).message || t('admin.subscription.somethingWrong'));
        } finally {
            setAction(false);
        }
    }

    async function switchPlan(plan) {
        if (plan.price_cents > 0) {
            setAction(true);
            try {
                const res = await api.post('/billing/checkout', { plan_id: plan.id });
                if (res.data.changed) {
                    toast.success(res.data.message);
                    await load();
                    return;
                }
                if (res.data.session?.redirect_url) {
                    window.location.href = res.data.session.redirect_url;
                    return;
                }
                toast.success(t('admin.subscription.checkoutCreated'));
                await load();
            } catch (e) {
                toast.error(fieldErrors(e).form || fieldErrors(e).message || t('admin.subscription.checkoutFailed'));
            } finally {
                setAction(false);
            }
            return;
        }
        await run(() => api.post('/my-subscription/switch', { plan_id: plan.id }), t('admin.subscription.switchedTo', { name: plan.name }));
    }

    async function cancel(product) {
        if (!(await confirm({ title: t('admin.subscription.cancelTitle'), message: t('admin.subscription.cancelMessage'), variant: 'danger' }))) return;
        await run(() => api.post('/my-subscription/cancel', product ? { product } : {}), t('admin.subscription.canceled'));
    }

    async function renew(product) {
        await run(() => api.post('/my-subscription/renew', product ? { product } : {}), t('admin.subscription.renewed'));
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    }

    if (error) {
        return (
            <div className="mx-auto max-w-md py-20">
                <Alert tone="error">{error}</Alert>
            </div>
        );
    }

    const plan = subscription?.plan;
    const onBundle = subscriptions.some((x) => x.product === 'suite' && x.status !== 'ended');
    const perProduct = subscriptions.filter((x) => x.product !== 'suite' && x.status !== 'ended');
    const currentPlanIds = subscriptions.map((x) => x.plan?.id);
    // A bundle tenant compares bundles; everyone else compares the per-product ladders.
    const visiblePlans = plans.filter((p) => (onBundle ? (p.product ?? 'suite') === 'suite' : (p.product ?? 'suite') !== 'suite' || subscriptions.length === 0));
    const endDate = subscription?.trial_ends_at || subscription?.current_period_end;
    const includedModules = modules || plan?.limits?.modules || [];
    const excludedModules = modulesAvailable.filter((m) => !includedModules.includes(m));
    const usageResources = RESOURCES.filter((r) => limits[r] !== undefined);

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.subscription.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">
                    {t('admin.subscription.subtitle', { name: tenant?.name || t('admin.subscription.subtitleFallback') })}
                </p>
            </div>

            <section>
                <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.sectionCurrentPlan')}</h3>
                {!subscription ? (
                    <Alert>{t('admin.subscription.noSubscription')}</Alert>
                ) : (
                    <>
                        <p className="flex flex-wrap items-center gap-2 text-sm text-gray-700">
                            <span className="text-lg font-bold text-gray-900">{plan?.name || '—'}</span>
                            <Badge>{statusLabel(t, subscription.status)}</Badge>
                            {plan && <Badge>{plan.billing_cycle}</Badge>}
                            <span className="text-gray-500">{plan ? formatPrice(plan) : t('admin.subscription.noActivePlan')}</span>
                        </p>
                        <p className="mt-1 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500">
                            <span>
                                <span className="text-gray-400">{subscription.trial_ends_at ? t('admin.subscription.trialEnds') : t('admin.subscription.periodEnds')}:</span>{' '}
                                {formatDate(endDate)}
                            </span>
                            <span>
                                <span className="text-gray-400">{t('admin.subscription.daysRemaining')}</span>{' '}
                                {daysRemaining(endDate) === null ? '—' : t('admin.subscription.daysSuffix', { days: daysRemaining(endDate) })}
                            </span>
                            <span>
                                <span className="text-gray-400">{t('admin.subscription.autoRenew')}</span> {subscription.auto_renew ? t('admin.subscription.autoRenewOn') : t('admin.subscription.autoRenewOff')}
                            </span>
                        </p>
                        {subscription.canceled_at && (
                            <p className="mt-1 text-sm text-amber-600">
                                {t('admin.subscription.canceledPrefix')} <Timestamp date={subscription.canceled_at} /> {t('admin.subscription.canceledSuffix')}
                            </p>
                        )}
                    </>
                )}
                {canManage && subscription && (
                    <div className="mt-4 flex items-center gap-2">
                        {subscription.billing_provider === 'stripe' && (
                            <Button size="md" variant="secondary" onClick={openPortal} loading={action}>
                                {t('admin.subscription.manageBilling')}
                            </Button>
                        )}
                        {subscription.status === 'canceled' || subscription.status === 'expired' ? (
                            <Button size="md" onClick={renew} loading={action}>
                                {t('admin.subscription.renew')}
                            </Button>
                        ) : subscription.status !== 'past_due' ? (
                            <Button size="md" variant="danger" onClick={cancel} loading={action}>
                                {t('admin.subscription.cancelSubscription')}
                            </Button>
                        ) : null}
                    </div>
                )}
            </section>

            {!onBundle && (
                <section>
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.yourProducts')}</h3>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {['tms', 'hrms'].map((prod) => {
                            const sub = perProduct.find((x) => x.product === prod);
                            return (
                                <div key={prod} className="rounded-xl border border-gray-200 bg-white p-4">
                                    <div className="flex items-center justify-between">
                                        <p className="font-semibold text-gray-900">{productName(t, prod)}</p>
                                        {sub ? <Badge>{sub.status.replace('_', ' ')}</Badge> : <Badge>{t('admin.subscription.notSubscribed')}</Badge>}
                                    </div>
                                    {sub ? (
                                        <>
                                            <p className="mt-1 text-sm text-gray-700">{sub.plan?.name}</p>
                                            <p className="text-xs text-gray-500">
                                                {sub.trial_ends_at ? t('admin.subscription.trialEndsOn', { date: formatDate(sub.trial_ends_at) }) : sub.current_period_end ? t('admin.subscription.' + (sub.auto_renew ? 'renewsOn' : 'endsOn'), { date: formatDate(sub.current_period_end) }) : ''}
                                            </p>
                                            {canManage && (
                                                <div className="mt-3 flex gap-2">
                                                    {sub.status === 'canceled' || sub.status === 'expired'
                                                        ? <Button size="sm" onClick={() => renew(prod)} loading={action}>{t('admin.subscription.renew')}</Button>
                                                        : sub.status !== 'past_due' && <Button size="sm" variant="danger" onClick={() => cancel(prod)} loading={action}>{t('admin.common.cancel')}</Button>}
                                                </div>
                                            )}
                                        </>
                                    ) : (
                                        <p className="mt-1 text-sm text-gray-500">{t('admin.subscription.notPartOfSubscription')}</p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </section>
            )}

            {subscription && (
                <section>
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.usage')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('admin.subscription.usageSubtitle')}</p>
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('admin.subscription.thResource')}</Th>
                                <Th align="right">{t('admin.subscription.thUsed')}</Th>
                                <Th align="right">{t('admin.subscription.thLimit')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {usageResources.length === 0 ? (
                                <TableEmpty colSpan={3}>{t('admin.subscription.noLimits')}</TableEmpty>
                            ) : (
                                usageResources.map((r) => {
                                    const used = usage[r] ?? 0;
                                    const limit = limits[r];
                                    const unlimited = limit === null || limit === undefined;
                                    const over = !unlimited && used > limit;

                                    return (
                                        <tr key={r} className="transition-colors duration-150 hover:bg-gray-50/60">
                                            <Td>
                                                <span className="font-medium text-gray-800">{t(`admin.subscription.resources.${r}`, { defaultValue: r })}</span>
                                                {over && (
                                                    <span className="mt-0.5 block text-xs text-red-600">
                                                        {t('admin.subscription.overLimit')}
                                                    </span>
                                                )}
                                            </Td>
                                            <Td align="right" className={`tabular-nums ${over ? 'font-semibold text-red-600' : 'text-gray-700'}`}>
                                                {used.toLocaleString()}
                                            </Td>
                                            <Td align="right" className="whitespace-nowrap tabular-nums text-gray-500">
                                                {unlimited ? t('admin.subscription.unlimited') : limit.toLocaleString()}
                                            </Td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </Table>
                </section>
            )}

            <section>
                <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.includedFeatures')}</h3>
                <p className="mb-3 text-xs text-gray-500">{t('admin.subscription.includedFeaturesSubtitle')}</p>
                {includedModules.length === 0 && <p className="mb-3 text-sm text-gray-500">{t('admin.subscription.noFeatureModules')}</p>}
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    {includedModules.length > 0 && (
                        <ModuleList title={t('admin.subscription.includedModulesTitle')} modules={includedModules} tone="included" />
                    )}
                    <ModuleList title={t('admin.subscription.notIncludedTitle')} modules={excludedModules} />
                </div>
            </section>

            <section>
                <h3 className="mb-3 text-lg font-bold text-gray-900">{t('admin.subscription.comparePlans')}</h3>
                {visiblePlans.length === 0 && <Alert>{t('admin.subscription.noPlans')}</Alert>}
                {visiblePlans.length > 0 && (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('admin.subscription.thPlan')}</Th>
                                <Th>{t('admin.subscription.thPrice')}</Th>
                                <Th>{t('admin.subscription.thTrial')}</Th>
                                <Th align="right">{t('admin.subscription.thModules')}</Th>
                                <Th>{t('admin.subscription.thCurrent')}</Th>
                                <Th align="right">{t('admin.common.actions')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {visiblePlans.map((p) => {
                                const isCurrent = currentPlanIds.includes(p.id);

                                return (
                                    <tr key={p.id} className="transition-colors duration-150 hover:bg-gray-50/60">
                                        <Td>
                                            <span className="font-medium text-gray-900">{p.name}</span>
                                            {p.product && p.product !== 'suite' && <span className="ml-2"><Badge>{productName(t, p.product)}</Badge></span>}
                                            {p.is_default && <span className="ml-2"><Badge>{t('admin.subscription.defaultBadge')}</Badge></span>}
                                            <span className="mt-0.5 block text-xs text-gray-500">
                                                {p.description || t('admin.subscription.noDescription')}
                                            </span>
                                            <span className="mt-0.5 block text-xs text-gray-500">
                                                <span className="tabular-nums font-semibold text-gray-800">{p.limits?.users ?? '∞'}</span> {t('admin.subscription.limitUsers')} ·{' '}
                                                <span className="tabular-nums font-semibold text-gray-800">{p.limits?.projects ?? '∞'}</span> {t('admin.subscription.limitProjects')} ·{' '}
                                                <span className="tabular-nums font-semibold text-gray-800">{p.limits?.tasks ?? '∞'}</span> {t('admin.subscription.limitTasks')}
                                            </span>
                                        </Td>
                                        <Td className="whitespace-nowrap font-medium text-gray-900">{formatPrice(p)}</Td>
                                        <Td className="whitespace-nowrap text-gray-500">
                                            {p.trial_duration_days ? t('admin.subscription.trialDays', { count: p.trial_duration_days }) : '—'}
                                        </Td>
                                        <Td align="right" className="tabular-nums">{(p.limits?.modules || []).length}</Td>
                                        <Td>{isCurrent ? <Badge>{t('admin.subscription.currentBadge')}</Badge> : <span className="text-gray-400">—</span>}</Td>
                                        <Td align="right" className="whitespace-nowrap">
                                            {canManage ? (
                                                <Button size="sm" variant={isCurrent ? 'secondary' : 'primary'} disabled={isCurrent} onClick={() => switchPlan(p)} loading={action}>
                                                    {isCurrent ? t('admin.subscription.currentPlanBtn') : t('admin.subscription.switchTo', { name: p.name })}
                                                </Button>
                                            ) : (
                                                <span className="text-sm text-gray-400">{isCurrent ? t('admin.subscription.yourCurrentPlan') : t('admin.subscription.askAdmin')}</span>
                                            )}
                                        </Td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                )}
            </section>

            {events.length > 0 && (
                <section>
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.billingActivity')}</h3>
                    <p className="mb-2 text-xs text-gray-500">{t('admin.subscription.billingActivitySubtitle')}</p>
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('admin.subscription.thEvent')}</Th>
                                <Th>{t('admin.subscription.thPlanChange')}</Th>
                                <Th align="right">{t('admin.subscription.thWhen')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((e) => (
                                <tr key={e.id} className="transition-colors duration-150 hover:bg-gray-50/60">
                                    <Td className="font-medium capitalize text-gray-900">{String(e.type).replace(/_/g, ' ')}</Td>
                                    <Td className="text-gray-500">
                                        {e.from_plan && e.to_plan && e.from_plan.id !== e.to_plan.id
                                            ? `${e.from_plan.name} → ${e.to_plan.name}`
                                            : '—'}
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                        <Timestamp date={e.created_at} />
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </section>
            )}

            {/* P6.1: invoices, each downloadable as a PDF */}
            <section>
                <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.invoices')}</h3>
                {invoices.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-gray-200 bg-gray-50/60 px-4 py-6 text-center text-xs text-gray-400">
                        {t('admin.subscription.noInvoices')}
                    </div>
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('admin.subscription.thInvoice')}</Th>
                                <Th>{t('admin.subscription.thAmount')}</Th>
                                <Th>{t('admin.subscription.thStatus')}</Th>
                                <Th>{t('admin.subscription.thIssued')}</Th>
                                <Th align="right">{t('admin.subscription.thPdf')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((inv) => (
                                <tr key={inv.id} className="transition-colors duration-150 hover:bg-gray-50/60">
                                    <Td className="font-mono text-xs text-gray-600">{inv.number}</Td>
                                    <Td className="font-medium text-gray-900">{inv.formatted_total}</Td>
                                    <Td>
                                        <Badge>{inv.status}</Badge>
                                    </Td>
                                    <Td className="whitespace-nowrap text-xs text-gray-500"><Timestamp date={inv.issued_at} /></Td>
                                    <Td align="right">
                                        <a
                                            href={`/api/billing/invoices/${inv.id}/pdf`}
                                            className="text-xs font-medium text-[var(--accent-soft-text)] hover:underline"
                                        >
                                            {t('admin.subscription.download')}
                                        </a>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </section>

            {/* Phase 6: Real billing history table */}
            <section>
                <h3 className="mb-2 text-sm font-semibold text-gray-900">{t('admin.subscription.billingHistory')}</h3>
                <p className="mb-2 text-xs text-gray-500">{t('admin.subscription.billingHistorySubtitle')}</p>
                {payments.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-gray-200 bg-gray-50/60 px-4 py-6 text-center text-xs text-gray-400">
                        {t('admin.subscription.noTransactions')}
                    </div>
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('admin.subscription.thTransaction')}</Th>
                                <Th>{t('admin.subscription.thPlan')}</Th>
                                <Th>{t('admin.subscription.thAmount')}</Th>
                                <Th>{t('admin.subscription.thProvider')}</Th>
                                <Th>{t('admin.subscription.thStatus')}</Th>
                                <Th align="right">{t('admin.subscription.thDate')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((p) => (
                                <tr key={p.id} className="transition-colors duration-150 hover:bg-gray-50/60">
                                    <Td className="font-mono text-xs text-gray-600">#{p.id}</Td>
                                    <Td className="font-medium text-gray-900">{p.plan_name || '—'}</Td>
                                    <Td className="font-medium text-gray-900">{p.formatted_amount}</Td>
                                    <Td>
                                        <Badge>{p.provider}</Badge>
                                    </Td>
                                    <Td>
                                        <Badge>{p.status}</Badge>
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap text-xs text-gray-400">
                                        <Timestamp date={p.created_at} />
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </section>
        </div>
    );
}

