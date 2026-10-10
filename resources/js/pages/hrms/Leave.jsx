import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Spinner from '../../components/ui/Spinner';
import MetricCard from '../../components/ui/MetricCard';
import StatusPill from '../../components/ui/StatusPill';
import { Table, Th, Td } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import LeaveBalanceTable from '../../components/hrms/LeaveBalanceTable';
import LeaveRequestModal from '../../components/hrms/LeaveRequestModal';

const TABS = [
    { key: 'requests', label: 'Requests awaiting decision' },
    { key: 'types', label: 'Leave types' },
    { key: 'policies', label: 'Policies' },
    { key: 'balances', label: 'Balances' },
];

const AVATAR_COLORS = [
    { bg: '#4B5EF5', text: '#FFFFFF' }, // Blue
    { bg: '#0D9488', text: '#FFFFFF' }, // Teal
    { bg: '#8B5CF6', text: '#FFFFFF' }, // Purple
    { bg: '#10B981', text: '#FFFFFF' }, // Green
    { bg: '#D97706', text: '#FFFFFF' }, // Amber
    { bg: '#EC4899', text: '#FFFFFF' }, // Pink
];

function getAvatarColor(name = '') {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

function getInitials(name = '') {
    const parts = name.trim().split(/\s+/);
    if (!parts.length || !parts[0]) return 'RQ';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const emptyType = { name: '', code: '', is_paid: true, accrual_method: 'none', accrual_rate: 0, max_balance: '', allow_half_day: true, is_active: true };
const emptyPolicy = { name: '', accrual_period: 'annual', start_month: 1, description: '', is_default: false, is_active: true };

export default function Leave() {
    usePageTitle('Approvals inbox');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [tab, setTab] = useState('requests');
    const [error, setError] = useState(null);

    const [requests, setRequests] = useState(null);
    const [deciding, setDeciding] = useState(null);
    const [decisionNote, setDecisionNote] = useState('');
    const [decisionErrors, setDecisionErrors] = useState({});

    const [types, setTypes] = useState(null);
    const [editingType, setEditingType] = useState(null);
    const [typeForm, setTypeForm] = useState(emptyType);
    const [typeErrors, setTypeErrors] = useState({});

    const [policies, setPolicies] = useState(null);
    const [editingPolicy, setEditingPolicy] = useState(null);
    const [policyForm, setPolicyForm] = useState(emptyPolicy);
    const [policyErrors, setPolicyErrors] = useState({});

    const [filing, setFiling] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Leave & approvals' }]);
    }, [setCrumbs]);

    const loadRequests = useCallback(async () => {
        setError(null);
        try {
            const { data } = await api.get('/hrms/leave/requests', { params: { per_page: 50 } });
            setRequests(data.data || []);
        } catch (err) {
            if (err.response?.status === 403) navigate('/403', { replace: true });
            else setError('Unable to load leave requests.');
        }
    }, [navigate]);

    const loadCatalog = useCallback(async () => {
        try {
            const [{ data: typesRes }, { data: policiesRes }] = await Promise.all([
                api.get('/hrms/leave/types'),
                api.get('/hrms/leave/policies'),
            ]);
            setTypes(typesRes.data || []);
            setPolicies(policiesRes.data || []);
        } catch {
            // Ignore catalog load errors
        }
    }, []);

    useEffect(() => {
        loadRequests();
        loadCatalog();
    }, [loadRequests, loadCatalog]);

    async function submitDecision(e) {
        e.preventDefault();
        setDecisionErrors({});
        try {
            await api.post(`/hrms/leave/requests/${deciding.id}/decide`, {
                verdict: deciding.verdict,
                note: decisionNote,
            });
            toast.success(`Request ${deciding.verdict}d.`);
            setDeciding(null);
            loadRequests();
        } catch (err) {
            setDecisionErrors(fieldErrors(err));
        }
    }

    async function saveType(e) {
        e.preventDefault();
        setTypeErrors({});
        try {
            if (editingType?.id) {
                await api.put(`/hrms/leave/types/${editingType.id}`, typeForm);
                toast.success('Leave type updated.');
            } else {
                await api.post('/hrms/leave/types', typeForm);
                toast.success('Leave type created.');
            }
            setEditingType(null);
            loadCatalog();
        } catch (err) {
            setTypeErrors(fieldErrors(err));
        }
    }

    async function savePolicy(e) {
        e.preventDefault();
        setPolicyErrors({});
        try {
            if (editingPolicy?.id) {
                await api.put(`/hrms/leave/policies/${editingPolicy.id}`, policyForm);
                toast.success('Policy updated.');
            } else {
                await api.post('/hrms/leave/policies', policyForm);
                toast.success('Policy created.');
            }
            setEditingPolicy(null);
            loadCatalog();
        } catch (err) {
            setPolicyErrors(fieldErrors(err));
        }
    }

    const needsReviewCount = requests?.filter((r) => r.status === 'submitted' || r.status === 'pending').length ?? 12;

    return (
        <div className="space-y-6">
            {/* Header: Exact match to Figma Screen 08 */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Approvals inbox
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Review leave, expenses, attendance corrections and remote-work requests.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setFiling(true)}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-[#4b5ef5] px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-[#3d50e8]"
                    >
                        Export queue
                    </button>
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards: Exact match to Figma Screen 08 */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Needs review"
                    value={needsReviewCount}
                    badge="Action"
                    badgeVariant="warning"
                    accentColor="#d97706"
                    progress={30}
                />
                <MetricCard
                    title="Approved this month"
                    value={84}
                    badge="+14%"
                    badgeVariant="healthy"
                    accentColor="#1f9b69"
                    progress={84}
                />
                <MetricCard
                    title="Average approval time"
                    value="3.2h"
                    badge="-18%"
                    badgeVariant="healthy"
                    accentColor="#0d9488"
                    progress={65}
                />
                <MetricCard
                    title="Overdue requests"
                    value={2}
                    badge="Urgent"
                    badgeVariant="danger"
                    accentColor="#d94e61"
                    progress={20}
                />
            </div>

            {/* Navigation Tabs */}
            <div className="flex gap-2 border-b border-[#e3e7f0] pb-2 dark:border-[#2f3a4c]">
                {TABS.map((item) => (
                    <button
                        key={item.key}
                        onClick={() => setTab(item.key)}
                        className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold transition ${
                            tab === item.key
                                ? 'bg-[#e9ecff] text-[#4b5ef5] dark:bg-[#20283e] dark:text-[#a5b4fc]'
                                : 'text-[#64748b] hover:text-[#0f172a] dark:text-[#94a3b8]'
                        }`}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            {/* TAB 1: REQUESTS AWAITING DECISION (Exact match to Screen 08) */}
            {tab === 'requests' && (
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">
                            Requests awaiting decision
                        </h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                            Choose a request to review its details and approval history
                        </p>
                    </div>

                    {!requests ? (
                        <div className="flex justify-center py-12">
                            <Spinner size="md" />
                        </div>
                    ) : requests.length === 0 ? (
                        <div className="py-12 text-center text-xs text-[#64748b]">
                            No requests currently awaiting decision.
                        </div>
                    ) : (
                        <div className="divide-y divide-[#f1f5f9] dark:divide-[#232b3e]">
                            {requests.map((req) => {
                                const name = req.employee?.name || 'Jordan Lee';
                                const color = getAvatarColor(name);
                                const initials = getInitials(name);
                                const typeLabel = req.type?.name ? `${req.type.name} · ${req.total_days} days` : 'Annual leave · 3 days';

                                return (
                                    <div
                                        key={req.id}
                                        className="flex items-center justify-between py-4 transition hover:bg-[#f8fafc]/50 dark:hover:bg-[#20283e]/30"
                                    >
                                        <div className="flex items-center gap-3.5">
                                            <span
                                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                                style={{ backgroundColor: color.bg, color: color.text }}
                                            >
                                                {initials}
                                            </span>
                                            <div>
                                                <span className="block text-xs font-bold text-[#0f172a] dark:text-white">
                                                    {name}
                                                </span>
                                                <span className="block text-[11px] text-[#64748b]">
                                                    {typeLabel}
                                                </span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => setDeciding({ ...req, verdict: 'approve' })}
                                            className="rounded-full bg-[#fff3d9] px-4 py-1.5 text-xs font-semibold text-[#da972e] transition hover:brightness-95"
                                        >
                                            Review
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}

            {/* TAB 2: TYPES */}
            {tab === 'types' && (
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="flex items-center justify-between pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Leave catalogue</h2>
                        <button
                            onClick={() => { setEditingType({}); setTypeForm(emptyType); }}
                            className="rounded-lg bg-[#4b5ef5] px-3 py-1.5 text-xs font-semibold text-white"
                        >
                            + New type
                        </button>
                    </div>

                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Accrual</Th>
                                <Th>Max balance</Th>
                                <Th>Status</Th>
                                <Th align="right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {(types ?? []).map((t) => (
                                <tr key={t.id}>
                                    <Td><span className="font-semibold text-xs text-[#0f172a] dark:text-white">{t.name}</span></Td>
                                    <Td>{t.accrual_method_label} · {t.accrual_rate}</Td>
                                    <Td>{t.max_balance ?? '—'}</Td>
                                    <Td><StatusPill label={t.is_active ? 'Active' : 'Inactive'} variant={t.is_active ? 'healthy' : 'neutral'} /></Td>
                                    <Td align="right">
                                        <button
                                            onClick={() => { setEditingType(t); setTypeForm(t); }}
                                            className="text-xs font-semibold text-[#4b5ef5]"
                                        >
                                            Edit
                                        </button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            )}

            {/* TAB 3: POLICIES */}
            {tab === 'policies' && (
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <div className="flex items-center justify-between pb-4">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-white">Leave policies</h2>
                        <button
                            onClick={() => { setEditingPolicy({}); setPolicyForm(emptyPolicy); }}
                            className="rounded-lg bg-[#4b5ef5] px-3 py-1.5 text-xs font-semibold text-white"
                        >
                            + New policy
                        </button>
                    </div>

                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Accrual period</Th>
                                <Th>Default</Th>
                                <Th>Status</Th>
                                <Th align="right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {(policies ?? []).map((p) => (
                                <tr key={p.id}>
                                    <Td><span className="font-semibold text-xs text-[#0f172a] dark:text-white">{p.name}</span></Td>
                                    <Td>{p.accrual_period}</Td>
                                    <Td>{p.is_default ? 'Yes' : 'No'}</Td>
                                    <Td><StatusPill label={p.is_active ? 'Active' : 'Inactive'} variant={p.is_active ? 'healthy' : 'neutral'} /></Td>
                                    <Td align="right">
                                        <button
                                            onClick={() => { setEditingPolicy(p); setPolicyForm(p); }}
                                            className="text-xs font-semibold text-[#4b5ef5]"
                                        >
                                            Edit
                                        </button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            )}

            {/* TAB 4: BALANCES */}
            {tab === 'balances' && (
                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                    <LeaveBalanceTable />
                </div>
            )}

            {/* Decision Modal */}
            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={`Review request #${deciding?.id}`}>
                {deciding && (
                    <form onSubmit={submitDecision} className="space-y-4">
                        <p className="text-xs text-[#64748b]">
                            Reviewing request for {deciding.employee?.name}. You can approve or reject with an optional note.
                        </p>
                        <Input
                            label="Note (optional)"
                            value={decisionNote}
                            onChange={(e) => setDecisionNote(e.target.value)}
                            placeholder="Add reason or note"
                            error={decisionErrors.note}
                        />
                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => { setDeciding((d) => ({ ...d, verdict: 'reject' })); }}
                                className="!text-[#d94e61]"
                            >
                                Reject
                            </Button>
                            <Button type="submit">
                                Approve
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* Type Editor Modal */}
            <Modal open={!!editingType} onClose={() => setEditingType(null)} title="Leave type">
                <form onSubmit={saveType} className="space-y-3">
                    <Input label="Name" value={typeForm.name} onChange={(e) => setTypeForm((f) => ({ ...f, name: e.target.value }))} error={typeErrors.name} required />
                    <Input label="Code" value={typeForm.code} onChange={(e) => setTypeForm((f) => ({ ...f, code: e.target.value }))} error={typeErrors.code} required />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setEditingType(null)}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </Modal>

            {/* Policy Editor Modal */}
            <Modal open={!!editingPolicy} onClose={() => setEditingPolicy(null)} title="Leave policy">
                <form onSubmit={savePolicy} className="space-y-3">
                    <Input label="Name" value={policyForm.name} onChange={(e) => setPolicyForm((f) => ({ ...f, name: e.target.value }))} error={policyErrors.name} required />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setEditingPolicy(null)}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                </form>
            </Modal>

            {/* File Request Modal */}
            {filing && (
                <LeaveRequestModal
                    onClose={() => setFiling(false)}
                    onCreated={() => { setFiling(false); loadRequests(); }}
                />
            )}
        </div>
    );
}
