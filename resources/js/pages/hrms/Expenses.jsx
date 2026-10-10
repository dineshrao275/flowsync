import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import MetricCard from '../../components/ui/MetricCard';
import StatusPill from '../../components/ui/StatusPill';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const MOCK_CLAIMS = [
    {
        id: 'mock-1',
        initials: 'AC',
        employee: 'Alex Chen',
        categoryProject: 'Travel · Q4 Roadmap',
        amount: '₹35,200',
        submitted: 'Oct 07',
        approver: 'Sarah Lee',
        status: 'Pending',
        variant: 'warning',
        color: '#4B5EF5',
    },
    {
        id: 'mock-2',
        initials: 'MC',
        employee: 'Michael Chen',
        categoryProject: 'Equipment · Platform',
        amount: '₹12,800',
        submitted: 'Oct 06',
        approver: 'Elena Rostova',
        status: 'Approved',
        variant: 'healthy',
        color: '#1F9B69',
    },
    {
        id: 'mock-3',
        initials: 'SK',
        employee: 'Samira Khan',
        categoryProject: 'Meals · Design',
        amount: '₹2,450',
        submitted: 'Oct 05',
        approver: 'Alex Rivera',
        status: 'Pending',
        variant: 'warning',
        color: '#7B61FF',
    },
    {
        id: 'mock-4',
        initials: 'JM',
        employee: 'Jordan Miller',
        categoryProject: 'Transport · Finance',
        amount: '₹1,280',
        submitted: 'Oct 04',
        approver: 'Priya Shah',
        status: 'Approved',
        variant: 'healthy',
        color: '#00A884',
    },
    {
        id: 'mock-5',
        initials: 'CD',
        employee: 'Chloe Duong',
        categoryProject: 'Conference · Mobile',
        amount: '₹18,400',
        submitted: 'Oct 03',
        approver: 'Elena Rostova',
        status: 'Needs review',
        variant: 'danger',
        color: '#4B5EF5',
    },
];

export default function Expenses() {
    usePageTitle('Expenses & reimbursements');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canDecide = can('hrms.expenses.approve') || can('permission:hrms.expenses.approve');
    const canManage = can('hrms.expenses.manage') || can('permission:hrms.expenses.manage');

    const [claims, setClaims] = useState(null);
    const [categories, setCategories] = useState([]);
    const [employees, setEmployees] = useState([]);
    const [filters, setFilters] = useState({ status: '', period_year: '', period_month: '' });
    const [searchQuery, setSearchQuery] = useState('');
    const [error, setError] = useState(null);

    const [deciding, setDeciding] = useState(null);
    const [verdict, setVerdict] = useState('approve');
    const [approvedAmount, setApprovedAmount] = useState('');
    const [reason, setReason] = useState('');
    const [decideErrors, setDecideErrors] = useState({});

    const [newExpenseModal, setNewExpenseModal] = useState(false);
    const [newExpenseForm, setNewExpenseForm] = useState({
        employee_id: '',
        category_id: '',
        amount: '',
        description: '',
        incurred_at: '',
    });

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Expenses' }]);
    }, [setCrumbs]);

    const fail = useCallback(
        (message) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }
            setError(message);
        },
        [navigate],
    );

    const load = useCallback(() => {
        setError(null);
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));

        return Promise.all([
            api.get('/hrms/expenses/claims', { params }).then(({ data }) => setClaims(data.claims ?? [])),
            api.get('/hrms/expenses/categories').then(({ data }) => setCategories(data.categories ?? [])),
        ]).catch(fail('Unable to load claims.'));
    }, [filters, fail]);

    useEffect(() => {
        load();
        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters]);

    function openDecide(claim) {
        setDeciding(claim);
        setVerdict('approve');
        setApprovedAmount(claim.total_amount ?? '');
        setReason('');
        setDecideErrors({});
    }

    async function submitDecision(e) {
        e.preventDefault();
        setDecideErrors({});
        try {
            await api.post(`/hrms/expenses/claims/${deciding.id}/decide`, {
                action: verdict,
                approved_amount: verdict === 'approve' ? Number(approvedAmount) : undefined,
                rejection_reason: verdict === 'reject' ? reason : undefined,
            });
            toast.success(`Claim ${verdict}d.`);
            setDeciding(null);
            load();
        } catch (err) {
            setDecideErrors(fieldErrors(err));
        }
    }

    async function submitNewExpense(e) {
        e.preventDefault();
        try {
            await api.post('/hrms/expenses/claims', newExpenseForm);
            toast.success('Expense claim submitted.');
            setNewExpenseModal(false);
            setNewExpenseForm({ employee_id: '', category_id: '', amount: '', description: '', incurred_at: '' });
            load();
        } catch (err) {
            toast.error(err.response?.data?.message || 'Failed to submit claim.');
        }
    }

    // Map real claims or fallback to mock
    const hasRealClaims = claims && claims.length > 0;
    const displayClaims = hasRealClaims
        ? claims.map((c) => {
              const empName = c.employee?.name || c.employee?.display_name || 'Employee';
              const catName = c.category?.name || 'General';
              const projName = c.project?.name ? ` · ${c.project.name}` : '';
              const statusLabel = c.status === 'approved' ? 'Approved' : c.status === 'pending' ? 'Pending' : c.status;
              const statusVariant = c.status === 'approved' ? 'healthy' : c.status === 'pending' ? 'warning' : 'neutral';
              const inits = empName.split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase();

              return {
                  id: c.id,
                  initials: inits,
                  employee: empName,
                  categoryProject: `${catName}${projName}`,
                  amount: `₹${Number(c.total_amount || 0).toLocaleString('en-IN')}`,
                  submitted: c.created_at ? new Date(c.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : 'Recent',
                  approver: c.approver?.name || 'Pending',
                  status: statusLabel,
                  variant: statusVariant,
                  color: '#4B5EF5',
                  raw: c,
              };
          })
        : MOCK_CLAIMS;

    const filteredClaims = displayClaims.filter((c) => {
        if (!searchQuery) return true;
        const q = searchQuery.toLowerCase();
        return c.employee.toLowerCase().includes(q) || c.categoryProject.toLowerCase().includes(q);
    });

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Expenses & reimbursements</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Review claims, apply policy limits and track payouts into payroll.
                    </p>
                </div>

                <button
                    type="button"
                    onClick={() => setNewExpenseModal(true)}
                    className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                >
                    + New expense
                </button>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Pending claims"
                    value={18}
                    pillText="Review"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Awaiting payout"
                    value="₹1.24L"
                    pillText="This cycle"
                    pillVariant="healthy"
                    accentColor="#4B5EF5"
                />
                <MetricCard
                    label="Approved this month"
                    value={84}
                    pillText="+14%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Policy exceptions"
                    value={3}
                    pillText="Attention"
                    pillVariant="healthy"
                    accentColor="#E05260"
                />
            </div>

            {/* Main Card: Expense claims */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Expense claims</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                        Search and filter by employee, category, project and approval status.
                    </p>
                </div>

                {/* Filter Toolbar */}
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <div className="relative min-w-[260px] flex-1 max-w-sm">
                        <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[#8C96A8] text-sm">
                            ⌕
                        </span>
                        <input
                            type="text"
                            placeholder="Search employee or claim..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full rounded-lg border border-[#E5E8F0] bg-white py-1.5 pl-8 pr-3 text-[13px] text-[#171C2C] placeholder-[#8C96A8] focus:border-[#4B5EF5] focus:outline-none"
                        />
                    </div>

                    <button
                        type="button"
                        onClick={() => {
                            const next = filters.status === '' ? 'pending' : filters.status === 'pending' ? 'approved' : '';
                            setFilters({ ...filters, status: next });
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-[#E5E8F0] bg-[#F4F6FB] px-3.5 py-1.5 text-[12px] font-medium text-[#171C2C] hover:bg-[#EBEFF8] transition-colors"
                    >
                        <span>Status{filters.status ? `: ${filters.status}` : ''}</span>
                        <span className="text-[10px] text-[#8C96A8]">⌄</span>
                    </button>

                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 rounded-lg border border-[#E5E8F0] bg-[#F4F6FB] px-3.5 py-1.5 text-[12px] font-medium text-[#171C2C] hover:bg-[#EBEFF8] transition-colors"
                    >
                        <span>Category</span>
                        <span className="text-[10px] text-[#8C96A8]">⌄</span>
                    </button>

                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 rounded-lg border border-[#E5E8F0] bg-[#F4F6FB] px-3.5 py-1.5 text-[12px] font-medium text-[#171C2C] hover:bg-[#EBEFF8] transition-colors"
                    >
                        <span>Date range</span>
                        <span className="text-[10px] text-[#8C96A8]">⌄</span>
                    </button>
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b border-[#F0F2F7] text-[11px] font-semibold uppercase tracking-wider text-[#8C96A8]">
                                <th className="pb-3 pl-2">EMPLOYEE</th>
                                <th className="pb-3">CATEGORY / PROJECT</th>
                                <th className="pb-3">AMOUNT</th>
                                <th className="pb-3">SUBMITTED</th>
                                <th className="pb-3">APPROVER</th>
                                <th className="pb-3 text-right pr-2">STATUS</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#F0F2F7]">
                            {filteredClaims.map((claim) => (
                                <tr key={claim.id} className="hover:bg-[#F8FAFD] transition-colors">
                                    <td className="py-3.5 pl-2">
                                        <div className="flex items-center gap-3">
                                            <div
                                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white shadow-xs"
                                                style={{ backgroundColor: claim.color }}
                                            >
                                                {claim.initials}
                                            </div>
                                            <span className="text-[13px] font-medium text-[#171C2C]">{claim.employee}</span>
                                        </div>
                                    </td>
                                    <td className="py-3.5 text-[13px] text-[#5A6478]">{claim.categoryProject}</td>
                                    <td className="py-3.5 text-[13px] font-semibold text-[#171C2C]">{claim.amount}</td>
                                    <td className="py-3.5 text-[13px] text-[#8C96A8]">{claim.submitted}</td>
                                    <td className="py-3.5 text-[13px] text-[#5A6478]">{claim.approver}</td>
                                    <td className="py-3.5 text-right pr-2">
                                        <div className="inline-flex items-center gap-2">
                                            <StatusPill variant={claim.variant}>{claim.status}</StatusPill>
                                            {canDecide && claim.raw && claim.raw.status === 'pending' && (
                                                <button
                                                    type="button"
                                                    onClick={() => openDecide(claim.raw)}
                                                    className="rounded border border-[#E5E8F0] bg-white px-2 py-0.5 text-[11px] font-medium text-[#4B5EF5] hover:bg-[#F4F6FB]"
                                                >
                                                    Decide
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Decide Modal */}
            <Modal open={!!deciding} onClose={() => setDeciding(null)} title="Decide on claim" size="md">
                {deciding && (
                    <form onSubmit={submitDecision} className="space-y-4">
                        <div className="rounded-lg bg-[#F4F6FB] p-3 text-[13px] text-[#171C2C]">
                            <p><strong>Employee:</strong> {deciding.employee?.name || 'Employee'}</p>
                            <p><strong>Filed amount:</strong> ₹{Number(deciding.total_amount || 0).toLocaleString('en-IN')}</p>
                        </div>

                        <div className="flex gap-4">
                            <label className="flex items-center gap-2 text-[13px] font-medium text-[#171C2C]">
                                <input
                                    type="radio"
                                    name="verdict"
                                    checked={verdict === 'approve'}
                                    onChange={() => setVerdict('approve')}
                                />
                                Approve
                            </label>
                            <label className="flex items-center gap-2 text-[13px] font-medium text-[#171C2C]">
                                <input
                                    type="radio"
                                    name="verdict"
                                    checked={verdict === 'reject'}
                                    onChange={() => setVerdict('reject')}
                                />
                                Reject
                            </label>
                        </div>

                        {verdict === 'approve' ? (
                            <Input
                                label="Approved amount (INR)"
                                type="number"
                                value={approvedAmount}
                                onChange={(e) => setApprovedAmount(e.target.value)}
                                error={decideErrors.approved_amount}
                            />
                        ) : (
                            <Input
                                label="Rejection reason"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                error={decideErrors.rejection_reason}
                            />
                        )}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button variant="secondary" onClick={() => setDeciding(null)}>
                                Cancel
                            </Button>
                            <Button type="submit">Submit decision</Button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* New Expense Modal */}
            <Modal open={newExpenseModal} onClose={() => setNewExpenseModal(false)} title="New expense claim" size="md">
                <form onSubmit={submitNewExpense} className="space-y-4">
                    {canManage && (
                        <Select
                            label="Employee"
                            value={newExpenseForm.employee_id}
                            onChange={(e) => setNewExpenseForm({ ...newExpenseForm, employee_id: e.target.value })}
                        >
                            <option value="">Select employee</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </Select>
                    )}
                    <Select
                        label="Category"
                        value={newExpenseForm.category_id}
                        onChange={(e) => setNewExpenseForm({ ...newExpenseForm, category_id: e.target.value })}
                    >
                        <option value="">Select category</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label="Amount (INR)"
                        type="number"
                        placeholder="e.g. 2450"
                        value={newExpenseForm.amount}
                        onChange={(e) => setNewExpenseForm({ ...newExpenseForm, amount: e.target.value })}
                    />
                    <Input
                        label="Date incurred"
                        type="date"
                        value={newExpenseForm.incurred_at}
                        onChange={(e) => setNewExpenseForm({ ...newExpenseForm, incurred_at: e.target.value })}
                    />
                    <Input
                        label="Description"
                        placeholder="e.g. Travel tickets to client office"
                        value={newExpenseForm.description}
                        onChange={(e) => setNewExpenseForm({ ...newExpenseForm, description: e.target.value })}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setNewExpenseModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit">Submit claim</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
