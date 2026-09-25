import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
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

function UsageRow({ label, used, limit }) {
    const unlimited = limit === null || limit === undefined;
    const pct = unlimited ? (used > 0 ? 100 : 0) : Math.min(100, Math.round((used / Math.max(1, limit)) * 100));
    const over = !unlimited && used > limit;

    return (
        <div className="py-2">
            <div className="flex items-center justify-between text-sm">
                <span className="font-medium text-gray-700">{label}</span>
                <span className={over ? 'font-semibold text-red-600' : 'text-gray-500'}>
                    {used.toLocaleString()} / {unlimited ? '∞' : limit.toLocaleString()}
                </span>
            </div>
            <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full ${over ? 'bg-red-500' : 'bg-indigo-500'}`}
                    style={{ width: `${Math.min(100, pct)}%` }}
                />
            </div>
            {over && <p className="mt-1 text-xs text-red-600">Over the plan limit — you may not be able to create more.</p>}
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

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Subscription</h2>
                <p className="mt-1 text-sm text-gray-500">
                    {tenant?.name || 'Your workspace'} — plan, usage and billing periods.
                </p>
            </div>

            <Card
                title="Current plan"
                subtitle={plan ? formatPrice(plan) : 'No active plan'}
                actions={
                    <span className="flex items-center gap-2">
                        {subscription && <Badge>{STATUS_LABELS[subscription.status] || subscription.status}</Badge>}
                        {plan && <Badge>{plan.billing_cycle}</Badge>}
                    </span>
                }
            >
                {!subscription ? (
                    <Alert>No subscription yet. Pick a plan below to get started.</Alert>
                ) : (
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Plan</p>
                                <p className="mt-1 text-lg font-bold text-gray-900">{plan?.name || '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    {subscription.trial_ends_at ? 'Trial ends' : 'Period ends'}
                                </p>
                                <p className="mt-1 text-lg font-bold text-gray-900">{formatDate(endDate)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Days remaining</p>
                                <p className="mt-1 text-lg font-bold text-gray-900">
                                    {daysRemaining(endDate) === null ? '—' : `${daysRemaining(endDate)}d`}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Auto-renew</p>
                                <p className="mt-1 text-lg font-bold text-gray-900">
                                    {subscription.auto_renew ? 'On' : 'Off'}
                                </p>
                            </div>
                        </div>
                        {subscription.canceled_at && (
                            <p className="text-sm text-amber-600">
                                Canceled {formatDate(subscription.canceled_at)} — access continues until the period end.
                            </p>
                        )}
                    </div>
                )}
                {canManage && (
                    <div className="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4">
                        {subscription?.status === 'canceled' || subscription?.status === 'expired' ? (
                            <Button size="md" onClick={renew} loading={action}>
                                Renew
                            </Button>
                        ) : subscription && subscription.status !== 'past_due' ? (
                            <Button size="md" variant="danger" onClick={cancel} loading={action}>
                                Cancel subscription
                            </Button>
                        ) : null}
                    </div>
                )}
            </Card>

            {subscription && (
                <Card title="Usage" subtitle="Current counts against your plan limits">
                    <div className="divide-y divide-gray-100">
                        {RESOURCES.filter((r) => limits[r.key] !== undefined).map((r) => (
                            <UsageRow key={r.key} label={r.label} used={usage[r.key] ?? 0} limit={limits[r.key]} />
                        ))}
                    </div>
                </Card>
            )}

            <Card title="Included features" subtitle="Modules unlocked by your plan">
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {includedModules.length === 0 && <p className="text-sm text-gray-500">No feature modules included.</p>}
                    {includedModules.map((m) => (
                        <div key={m} className="flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">
                            <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M20 6L9 17l-5-5" />
                            </svg>
                            {MODULE_LABELS[m] || m}
                        </div>
                    ))}
                    {excludedModules.map((m) => (
                        <div key={m} className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500">
                            <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M18 6L6 18M6 6l12 12" />
                            </svg>
                            {MODULE_LABELS[m] || m}
                        </div>
                    ))}
                </div>
            </Card>

            <div>
                <h3 className="mb-3 text-lg font-bold text-gray-900">Compare plans</h3>
                {plans.length === 0 && <Alert>No plans are currently available.</Alert>}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {plans.map((p, index) => {
                        const isCurrent = plan?.id === p.id;
                        return (
                            <div key={p.id} className="animate-fade-in-up" style={{ animationDelay: `${index * 60}ms` }}>
                                <Card
                                    className={`h-full ${isCurrent ? 'ring-2 ring-indigo-500' : ''}`}
                                    title={
                                        <span className="flex items-center gap-2">
                                            {p.name}
                                            {p.is_default && <Badge>default</Badge>}
                                            {isCurrent && (
                                                <span className="inline-flex items-center rounded-full bg-indigo-600 px-2.5 py-0.5 text-xs font-medium text-white">
                                                    current
                                                </span>
                                            )}
                                        </span>
                                    }
                                    subtitle={p.description || 'No description'}
                                >
                                    <p className="text-sm font-semibold text-gray-900">{formatPrice(p)}</p>
                                    {p.trial_duration_days && <p className="mt-0.5 text-xs text-gray-500">{p.trial_duration_days}-day trial</p>}
                                    <div className="mt-4 space-y-1.5 text-sm text-gray-600">
                                        <p><span className="font-medium text-gray-900">{p.limits?.users ?? '∞'}</span> users</p>
                                        <p><span className="font-medium text-gray-900">{p.limits?.projects ?? '∞'}</span> projects</p>
                                        <p><span className="font-medium text-gray-900">{p.limits?.tasks ?? '∞'}</span> tasks</p>
                                        <p><span className="font-medium text-gray-900">{(p.limits?.modules || []).length}</span> modules</p>
                                    </div>
                                    <div className="mt-4 border-t border-gray-100 pt-3">
                                        {canManage ? (
                                            <Button size="md" variant={isCurrent ? 'secondary' : 'primary'} disabled={isCurrent} onClick={() => switchPlan(p)} loading={action}>
                                                {isCurrent ? 'Current plan' : `Switch to ${p.name}`}
                                            </Button>
                                        ) : (
                                            <p className="text-sm text-gray-400">{isCurrent ? 'Your current plan' : 'Ask an admin to switch plans.'}</p>
                                        )}
                                    </div>
                                </Card>
                            </div>
                        );
                    })}
                </div>
            </div>

            {events.length > 0 && (
                <Card title="Billing activity" subtitle="Recent subscription events">
                    <ul className="divide-y divide-gray-100">
                        {events.map((e) => (
                            <li key={e.id} className="flex items-center justify-between py-2.5 text-sm">
                                <div>
                                    <span className="font-medium capitalize text-gray-900">{String(e.type).replace(/_/g, ' ')}</span>
                                    {e.from_plan && e.to_plan && e.from_plan.id !== e.to_plan.id && (
                                        <span className="ml-2 text-gray-500">
                                            {e.from_plan.name} → {e.to_plan.name}
                                        </span>
                                    )}
                                </div>
                                <span className="text-xs text-gray-400">{formatDate(e.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}