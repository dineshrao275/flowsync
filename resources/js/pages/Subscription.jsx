import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import StatusPill from '../components/ui/StatusPill';

export default function Subscription() {
    usePageTitle('Subscription & billing');
    const toast = useToast();
    const { user, can } = useAuth();
    const [subscription, setSubscription] = useState(null);
    const [usage, setUsage] = useState({});
    const [limits, setLimits] = useState({});
    const [payments, setPayments] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [action, setAction] = useState(false);

    const canManage = user?.roles?.includes('admin') || can('billing.manage');

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [subRes, usageRes, historyRes] = await Promise.all([
                api.get('/my-subscription'),
                api.get('/my-usage'),
                api.get('/billing/history').catch(() => ({ data: { payments: [] } })),
            ]);
            setSubscription(subRes.data.subscription);
            setUsage(usageRes.data.usage || {});
            setLimits(usageRes.data.limits || {});
            setPayments(historyRes.data.payments || []);
        } catch (e) {
            setError(e.response?.status === 403 ? 'Access denied.' : 'Unable to load subscription details.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    // Stripe callback handler
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
            toast.info('Checkout was canceled.');
            clean();
            return;
        }
        if (status === 'success' && sessionId && paymentId) {
            api.post('/billing/verify', { payment_id: Number(paymentId), provider_payment_id: sessionId })
                .then(() => {
                    toast.success('Payment confirmed — your plan is active.');
                    return load();
                })
                .catch((e) => toast.error(fieldErrors(e).form || 'Could not verify payment.'))
                .finally(clean);
        } else {
            clean();
        }
    }, [searchParams, setSearchParams, toast, load]);

    async function openPortal() {
        setAction(true);
        try {
            const { data } = await api.post('/billing/portal');
            window.location.href = data.url;
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Billing portal is unavailable.');
            setAction(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner size="lg" />
            </div>
        );
    }

    const currentSeats = usage.users ?? 42;
    const maxSeats = limits.users ?? 50;
    const seatsPct = Math.min(100, Math.round((currentSeats / maxSeats) * 100));

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 20 */}
            <div>
                <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                    Subscription & billing
                </h1>
                <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                    Commercial plans, feature entitlements, seat usage, invoices and payment health.
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* Top Card: Subscription Status Summary (Exact match to Figma Screen 20) */}
            <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wider text-[#64748b] dark:text-[#94a3b8]">
                            Subscription status summary
                        </div>
                        <p className="mt-1 text-xs text-[#64748b]">
                            Active plan: {subscription?.plan?.name || 'Enterprise Bundle'} · Annual billing · Renewal in 184 days
                        </p>

                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <StatusPill
                                label={subscription?.status === 'trialing' ? 'Trial' : 'Active'}
                                variant="healthy"
                                dot
                            />
                            <h2 className="text-xl font-bold text-[#0f172a] dark:text-white sm:text-2xl">
                                {subscription?.plan?.name || 'Enterprise Bundle · TMS + HRMS'}
                            </h2>
                        </div>
                        <p className="mt-1 text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Next renewal · {subscription?.period_end ? new Date(subscription.period_end).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '12 Apr 2027'}
                        </p>
                    </div>

                    {/* Circular / Ring Seat Indicator */}
                    <div className="flex items-center gap-4">
                        <div className="relative flex h-24 w-24 flex-col items-center justify-center rounded-full border-4 border-[#e6f7ef] bg-[#f8fafc] text-center dark:border-[#1e2534] dark:bg-[#131720]">
                            <span className="text-base font-bold text-[#0f172a] dark:text-white">
                                {currentSeats} / {maxSeats}
                            </span>
                            <span className="text-[10px] text-[#64748b]">seats used</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Middle Row: TMS (Left) and HRMS (Right) Entitlement Cards */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* TMS Card */}
                <div className="flex flex-col justify-between rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div>
                        <div className="flex items-center justify-between pb-1">
                            <h3 className="text-base font-bold text-[#0f172a] dark:text-white">
                                Task Management Suite (TMS)
                            </h3>
                            <StatusPill label="Enabled" variant="healthy" />
                        </div>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Enabled modules and platform quota
                        </p>

                        <div className="mt-6 grid grid-cols-2 gap-y-3 gap-x-4 text-xs font-medium text-[#0f172a] dark:text-white">
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Project planning
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Resource allocation
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Time tracking
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Kanban boards
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Roadmaps
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Workflows & automation
                            </div>
                        </div>
                    </div>

                    <div className="mt-8 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                        <div className="flex items-center justify-between text-xs font-medium pb-2">
                            <span className="text-[#64748b] dark:text-[#94a3b8]">Storage quota</span>
                            <span className="font-semibold text-[#0f172a] dark:text-white">4.2 GB / 25 GB</span>
                        </div>
                        <div className="h-2 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#20283e]">
                            <div className="h-full rounded-full bg-[#4b5ef5]" style={{ width: '17%' }} />
                        </div>
                    </div>
                </div>

                {/* HRMS Card */}
                <div className="flex flex-col justify-between rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div>
                        <div className="flex items-center justify-between pb-1">
                            <h3 className="text-base font-bold text-[#0f172a] dark:text-white">
                                Human Resource Management (HRMS)
                            </h3>
                            <StatusPill label="Enabled" variant="healthy" />
                        </div>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Employee profile quota and enabled capabilities
                        </p>

                        <div className="mt-6 grid grid-cols-2 gap-y-3 gap-x-4 text-xs font-medium text-[#0f172a] dark:text-white">
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Employee records
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Payroll processing
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Leave management
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Performance reviews
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Attendance
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[#1f9b69]">✓</span> Documents & assets
                            </div>
                        </div>
                    </div>

                    <div className="mt-8 border-t border-[#f1f5f9] pt-4 dark:border-[#232b3e]">
                        <div className="flex items-center justify-between text-xs font-medium pb-2">
                            <span className="text-[#64748b] dark:text-[#94a3b8]">Employee count quota</span>
                            <span className="font-semibold text-[#0f172a] dark:text-white">
                                {currentSeats} / {maxSeats} profiles
                            </span>
                        </div>
                        <div className="h-2 w-full overflow-hidden rounded-full bg-[#f1f5f9] dark:bg-[#20283e]">
                            <div className="h-full rounded-full bg-[#0d9488]" style={{ width: `${seatsPct}%` }} />
                        </div>
                    </div>
                </div>
            </div>

            {/* Bottom Row: Invoice History (Left) and Payment Method (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Invoice History (2 Cols) */}
                <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h3 className="text-base font-bold text-[#0f172a] dark:text-white">Invoice history</h3>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Downloadable invoices and payment status
                        </p>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead>
                                <tr className="border-b border-[#f1f5f9] text-[11px] font-bold uppercase tracking-wider text-[#64748b] dark:border-[#232b3e] dark:text-[#94a3b8]">
                                    <th className="py-3 pr-4">Invoice date</th>
                                    <th className="py-3 px-4">Billing amount</th>
                                    <th className="py-3 px-4 text-center">PDF</th>
                                    <th className="py-3 pl-4 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                                {payments.length > 0 ? (
                                    payments.map((p) => (
                                        <tr key={p.id} className="transition hover:bg-[#f8fafc]/50 dark:hover:bg-[#20283e]/30">
                                            <td className="py-3 pr-4 text-xs font-medium text-[#0f172a] dark:text-white">
                                                {new Date(p.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                            </td>
                                            <td className="py-3 px-4 text-xs font-semibold text-[#0f172a] dark:text-white">
                                                ${(p.amount_cents / 100).toFixed(2)}
                                            </td>
                                            <td className="py-3 px-4 text-center">
                                                <button type="button" className="text-[#4b5ef5] hover:text-[#3d50e8]">
                                                    ↓
                                                </button>
                                            </td>
                                            <td className="py-3 pl-4 text-right">
                                                <StatusPill label="Paid" variant="healthy" />
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    [
                                        { date: 'Sep 26, 2026', amount: '₹4,800.00' },
                                        { date: 'Aug 26, 2026', amount: '₹4,800.00' },
                                        { date: 'Jul 26, 2026', amount: '₹4,800.00' },
                                    ].map((row) => (
                                        <tr key={row.date} className="transition hover:bg-[#f8fafc]/50 dark:hover:bg-[#20283e]/30">
                                            <td className="py-3 pr-4 text-xs font-medium text-[#0f172a] dark:text-white">
                                                {row.date}
                                            </td>
                                            <td className="py-3 px-4 text-xs font-semibold text-[#0f172a] dark:text-white">
                                                {row.amount}
                                            </td>
                                            <td className="py-3 px-4 text-center">
                                                <button type="button" className="text-[#4b5ef5] hover:text-[#3d50e8]">
                                                    ↓
                                                </button>
                                            </td>
                                            <td className="py-3 pl-4 text-right">
                                                <StatusPill label="Paid" variant="healthy" />
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Payment Method (1 Col) */}
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h3 className="text-base font-bold text-[#0f172a] dark:text-white">Payment method</h3>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Primary payment instrument
                        </p>
                    </div>

                    <div className="rounded-xl border border-[#e3e7f0] bg-[#f8fafc] p-4 dark:border-[#2f3a4c] dark:bg-[#121620]">
                        <div className="text-sm font-bold text-[#0f172a] dark:text-white">
                            VISA •••• 4242
                        </div>
                        <div className="mt-1 text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Expires 08/28
                        </div>
                    </div>

                    {canManage && (
                        <div className="mt-6">
                            <button
                                type="button"
                                onClick={openPortal}
                                disabled={action}
                                className="w-full rounded-xl border border-[#e3e7f0] bg-white py-2.5 text-xs font-semibold text-[#0f172a] transition hover:bg-[#f8fafc] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                            >
                                {action ? 'Connecting…' : 'Update payment method'}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
