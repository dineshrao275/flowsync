import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { formatDate, formatPrice } from '../utils/format';

const RESOURCES = [
    { key: 'users', label: 'Users' },
    { key: 'seats', label: 'Seats' },
    { key: 'workspaces', label: 'Workspaces' },
    { key: 'projects', label: 'Projects' },
    { key: 'tasks', label: 'Tasks' },
];

const MODULE_LABELS = {
    time_tracking: 'Time tracking',
    reports: 'Reports & analytics',
    global_search: 'Global search',
    api: 'API access',
    branding: 'Custom branding',
    audit_export: 'Audit export',
};

const STATUS_LABELS = {
    trialing: 'Trial',
    active: 'Active',
    past_due: 'Past due',
    canceled: 'Canceled',
    expired: 'Expired',
    ended: 'Ended',
};

function daysRemaining(iso) {
    if (!iso) return null;
    return Math.max(0, Math.ceil((new Date(iso) - new Date()) / 86400000));
}

function ModuleList({ title, modules, tone }) {
    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">{title}</p>
            {modules.length === 0 ? (
                <p className="mt-2 text-sm text-gray-500">None.</p>
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
                            {MODULE_LABELS[m] || m}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Subscription() {
    usePageTitle('Subscription');
    const toast = useToast();
    const { user } = useAuth();
    const [subscription, setSubscription] = useState(null);
    const [tenant, setTenant] = useState(null);
    const [events, setEvents] = useState([]);
    const [usage, setUsage] = useState({});
    const [limits, setLimits] = useState({});
    const [modules, setModules] = useState(null);
    const [modulesAvailable, setModulesAvailable] = useState([]);
    const [plans, setPlans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [action, setAction] = useState(null);

    const canManage = user?.roles?.includes('admin');

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [subRes, usageRes, plansRes] = await Promise.all([
                api.get('/my-subscription'),
                api.get('/my-usage'),
                api.get('/plans'),
            ]);
            setSubscription(subRes.data.subscription);
            setTenant(subRes.data.tenant);
            setEvents(subRes.data.events || []);
            setUsage(usageRes.data.usage || {});
            setLimits(usageRes.data.limits || {});
            setModules(usageRes.data.modules ?? null);
            setModulesAvailable(usageRes.data.modules_available || []);
            setPlans(plansRes.data.plans || []);
        } catch (e) {
            setError(e.response?.status === 404 ? 'No tenant context for this page.' : 'Unable to load subscription details.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function run(fn, successMessage) {
        setAction(true);
        try {
            await fn();
            toast.success(successMessage);
            await load();
        } catch (e) {
            toast.error(fieldErrors(e).form || fieldErrors(e).message || 'Something went wrong.');
        } finally {
            setAction(false);
        }
    }

    async function switchPlan(plan) {
        await run(() => api.post('/my-subscription/switch', { plan_id: plan.id }), `Switched to ${plan.name}.`);
    }

    async function cancel() {
        if (!window.confirm('Cancel the subscription at the end of the current period?')) return;
        await run(() => api.post('/my-subscription/cancel'), 'Subscription canceled.');
    }

    async function renew() {
        await run(() => api.post('/my-subscription/renew'), 'Subscription renewed.');
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
    const endDate = subscription?.trial_ends_at || subscription?.current_period_end;
    const includedModules = modules || plan?.limits?.modules || [];
    const excludedModules = modulesAvailable.filter((m) => !includedModules.includes(m));
    const usageResources = RESOURCES.filter((r) => limits[r.key] !== undefined);

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Subscription</h2>
                <p className="mt-1 text-sm text-gray-500">
                    {tenant?.name || 'Your workspace'} — plan, usage and billing periods.
                </p>
            </div>

            <section>
                <h3 className="mb-2 text-sm font-semibold text-gray-900">Current plan</h3>
                {!subscription ? (
                    <Alert>No subscription yet. Pick a plan below to get started.</Alert>
                ) : (
                    <>
                        <p className="flex flex-wrap items-center gap-2 text-sm text-gray-700">
                            <span className="text-lg font-bold text-gray-900">{plan?.name || '—'}</span>
                            <Badge>{STATUS_LABELS[subscription.status] || subscription.status}</Badge>
                            {plan && <Badge>{plan.billing_cycle}</Badge>}
                            <span className="text-gray-500">{plan ? formatPrice(plan) : 'No active plan'}</span>
                        </p>
                        <p className="mt-1 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500">
                            <span>
                                <span className="text-gray-400">{subscription.trial_ends_at ? 'Trial ends' : 'Period ends'}:</span>{' '}
                                {formatDate(endDate)}
                            </span>
                            <span>
                                <span className="text-gray-400">Days remaining:</span>{' '}
                                {daysRemaining(endDate) === null ? '—' : `${daysRemaining(endDate)}d`}
                            </span>
                            <span>
                                <span className="text-gray-400">Auto-renew:</span> {subscription.auto_renew ? 'On' : 'Off'}
                            </span>
                        </p>
                        {subscription.canceled_at && (
                            <p className="mt-1 text-sm text-amber-600">
                                Canceled {formatDate(subscription.canceled_at)} — access continues until the period end.
                            </p>
                        )}
                    </>
                )}
                {canManage && subscription && (
                    <div className="mt-4 flex items-center gap-2">
                        {subscription.status === 'canceled' || subscription.status === 'expired' ? (
                            <Button size="md" onClick={renew} loading={action}>
                                Renew
                            </Button>
                        ) : subscription.status !== 'past_due' ? (
                            <Button size="md" variant="danger" onClick={cancel} loading={action}>
                                Cancel subscription
                            </Button>
                        ) : null}
                    </div>
                )}
            </section>

            {subscription && (
                <section>
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">Usage</h3>
                    <p className="mb-2 text-xs text-gray-500">Current counts against your plan limits</p>
                    <Table>
                        <thead>
                            <tr>
                                <Th>Resource</Th>
                                <Th align="right">Used</Th>
                                <Th align="right">Limit</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {usageResources.length === 0 ? (
                                <TableEmpty colSpan={3}>No limits apply to this plan.</TableEmpty>
                            ) : (
                                usageResources.map((r) => {
                                    const used = usage[r.key] ?? 0;
                                    const limit = limits[r.key];
                                    const unlimited = limit === null || limit === undefined;
                                    const over = !unlimited && used > limit;

                                    return (
                                        <tr key={r.key} className="transition-colors duration-150 hover:bg-gray-50/60">
                                            <Td>
                                                <span className="font-medium text-gray-800">{r.label}</span>
                                                {over && (
                                                    <span className="mt-0.5 block text-xs text-red-600">
                                                        Over the plan limit — you may not be able to create more.
                                                    </span>
                                                )}
                                            </Td>
                                            <Td align="right" className={`tabular-nums ${over ? 'font-semibold text-red-600' : 'text-gray-700'}`}>
                                                {used.toLocaleString()}
                                            </Td>
                                            <Td align="right" className="whitespace-nowrap tabular-nums text-gray-500">
                                                {unlimited ? 'Unlimited' : limit.toLocaleString()}
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
                <h3 className="mb-2 text-sm font-semibold text-gray-900">Included features</h3>
                <p className="mb-3 text-xs text-gray-500">Modules unlocked by your plan</p>
                {includedModules.length === 0 && <p className="mb-3 text-sm text-gray-500">No feature modules included.</p>}
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    {includedModules.length > 0 && (
                        <ModuleList title="Included modules" modules={includedModules} tone="included" />
                    )}
                    <ModuleList title="Not included" modules={excludedModules} />
                </div>
            </section>

            <section>
                <h3 className="mb-3 text-lg font-bold text-gray-900">Compare plans</h3>
                {plans.length === 0 && <Alert>No plans are currently available.</Alert>}
                {plans.length > 0 && (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Plan</Th>
                                <Th>Price</Th>
                                <Th>Trial</Th>
                                <Th align="right">Modules</Th>
                                <Th>Current</Th>
                                <Th align="right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {plans.map((p) => {
                                const isCurrent = plan?.id === p.id;

                                return (
                                    <tr key={p.id} className="transition-colors duration-150 hover:bg-gray-50/60">
                                        <Td>
                                            <span className="font-medium text-gray-900">{p.name}</span>
                                            {p.is_default && <span className="ml-2"><Badge>default</Badge></span>}
                                            <span className="mt-0.5 block text-xs text-gray-500">
                                                {p.description || 'No description'}
                                            </span>
                                            <span className="mt-0.5 block text-xs text-gray-500">
                                                <span className="tabular-nums font-semibold text-gray-800">{p.limits?.users ?? '∞'}</span> users ·{' '}
                                                <span className="tabular-nums font-semibold text-gray-800">{p.limits?.projects ?? '∞'}</span> projects ·{' '}
                                                <span className="tabular-nums font-semibold text-gray-800">{p.limits?.tasks ?? '∞'}</span> tasks
                                            </span>
                                        </Td>
                                        <Td className="whitespace-nowrap font-medium text-gray-900">{formatPrice(p)}</Td>
                                        <Td className="whitespace-nowrap text-gray-500">
                                            {p.trial_duration_days ? `${p.trial_duration_days} days` : '—'}
                                        </Td>
                                        <Td align="right" className="tabular-nums">{(p.limits?.modules || []).length}</Td>
                                        <Td>{isCurrent ? <Badge>current</Badge> : <span className="text-gray-400">—</span>}</Td>
                                        <Td align="right" className="whitespace-nowrap">
                                            {canManage ? (
                                                <Button size="sm" variant={isCurrent ? 'secondary' : 'primary'} disabled={isCurrent} onClick={() => switchPlan(p)} loading={action}>
                                                    {isCurrent ? 'Current plan' : `Switch to ${p.name}`}
                                                </Button>
                                            ) : (
                                                <span className="text-sm text-gray-400">{isCurrent ? 'Your current plan' : 'Ask an admin to switch plans.'}</span>
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
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">Billing activity</h3>
                    <p className="mb-2 text-xs text-gray-500">Recent subscription events</p>
                    <Table>
                        <thead>
                            <tr>
                                <Th>Event</Th>
                                <Th>Plan change</Th>
                                <Th align="right">When</Th>
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
                                        {formatDate(e.created_at)}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </section>
            )}
        </div>
    );
}
