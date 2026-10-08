import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import ExpenseClaimModal from '../../components/hrms/ExpenseClaimModal';

const STATUSES = ['', 'draft', 'submitted', 'pending', 'approved', 'rejected', 'paid', 'cancelled'];

/**
 * The claims queue: every claim filterable by period and status, with the
 * decide drawer for approvers.
 *
 * A manager screen end to end — the route needs the view permission, and
 * the approve/reject buttons hide without the approve permission (the
 * backend 403s regardless). Partial approval is the point of the drawer:
 * the approved figure may come in under the filed total, but never
 * without the reason the claimant can act on.
 */
export default function Expenses() {
    usePageTitle('Expenses');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canDecide = can('hrms.expenses.approve');
    const canManage = can('hrms.expenses.manage');

    const [claims, setClaims] = useState(null);
    const [categories, setCategories] = useState([]);
    const [employees, setEmployees] = useState([]);
    const [filters, setFilters] = useState({ status: '', period_year: '', period_month: '' });
    const [error, setError] = useState(null);

    const [deciding, setDeciding] = useState(null);
    const [verdict, setVerdict] = useState('approve');
    const [approvedAmount, setApprovedAmount] = useState('');
    const [reason, setReason] = useState('');
    const [decideErrors, setDecideErrors] = useState({});

    const [categoryForm, setCategoryForm] = useState({ name: '', requires_receipt_above: '' });
    const [categoryErrors, setCategoryErrors] = useState({});

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

    async function decide(e) {
        e.preventDefault();
        setDecideErrors({});

        try {
            await api.post(`/hrms/expenses/claims/${deciding.id}/decide`, {
                verdict,
                approved_amount: approvedAmount === '' ? null : approvedAmount,
                reason: reason || null,
            });
            toast.success(verdict === 'approve' ? 'Claim approved.' : 'Claim rejected.');
            setDeciding(null);
            load();
        } catch (err) {
            setDecideErrors(fieldErrors(err));
        }
    }

    function employeeName(claim) {
        const found = employees.find((e) => String(e.id) === String(claim.employee_id));
        return found?.name ?? claim.employee?.name ?? `#${claim.employee_id}`;
    }

    async function saveCategory(e) {
        e.preventDefault();
        setCategoryErrors({});

        try {
            await api.post('/hrms/expenses/categories', {
                name: categoryForm.name,
                requires_receipt_above: categoryForm.requires_receipt_above === '' ? null : categoryForm.requires_receipt_above,
            });
            toast.success('Category created.');
            setCategoryForm({ name: '', requires_receipt_above: '' });
            load();
        } catch (err) {
            setCategoryErrors(fieldErrors(err));
        }
    }

    async function deleteCategory(category) {
        if (!window.confirm(`Delete “${category.name}”? Starter and in-use rows refuse.`)) return;

        try {
            await api.delete(`/hrms/expenses/categories/${category.id}`);
            toast.success('Category deleted.');
            load();
        } catch {
            setError('That category cannot be deleted.');
        }
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">Expenses</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {claims ? `${claims.length} claim${claims.length === 1 ? '' : 's'}` : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Select label="Status" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                        {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>)}
                    </Select>
                    <Input label="Year" value={filters.period_year} onChange={(e) => setFilters({ ...filters, period_year: e.target.value })} />
                    <Input label="Month" value={filters.period_month} onChange={(e) => setFilters({ ...filters, period_month: e.target.value })} />
                </div>
            </Card>

            {!claims ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : claims.length === 0 ? (
                <EmptyState title="No claims match" hint="File one from My expenses, or loosen the filters." />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>Claim</Th><Th>Employee</Th><Th>Period</Th><Th>Total</Th><Th>Approved</Th><Th>Status</Th>{canDecide && <Th><span className="sr-only">Actions</span></Th>}</tr>
                        </thead>
                        <tbody>
                            {claims.map((claim) => (
                                <tr key={claim.id}>
                                    <Td>
                                        <span className="font-medium text-gray-900">{claim.claim_number}</span>
                                        <span className="block text-xs text-gray-400">{claim.purpose}</span>
                                    </Td>
                                    <Td>{employeeName(claim)}</Td>
                                    <Td>{claim.period_year}-{String(claim.period_month).padStart(2, '0')}</Td>
                                    <Td>{claim.total_amount}</Td>
                                    <Td>{claim.approved_amount ?? '—'}</Td>
                                    <Td>{claim.status}</Td>
                                    {canDecide && (
                                        <Td>
                                            {claim.status === 'submitted' && (
                                                <Button size="sm" variant="secondary" onClick={() => openDecide(claim)}>Decide</Button>
                                            )}
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}

            {canManage && (
                <Card title="Claim categories" dense>
                    <ul className="mb-3 divide-y divide-gray-100 text-sm">
                        {categories.map((category) => (
                            <li key={category.id} className="flex items-center justify-between gap-2 py-1.5">
                                <span>
                                    {category.name}
                                    <span className="ml-2 text-xs text-gray-400">
                                        {category.is_system ? 'starter · ' : ''}{category.requires_receipt_above === null ? 'receipt optional' : `receipt over ${category.requires_receipt_above}`}
                                    </span>
                                </span>
                                <Button size="sm" variant="danger" onClick={() => deleteCategory(category)}>Delete</Button>
                            </li>
                        ))}
                    </ul>
                    <form onSubmit={saveCategory} className="grid gap-3 sm:grid-cols-3">
                        <Input label="Name" value={categoryForm.name} error={categoryErrors.name} onChange={(e) => setCategoryForm({ ...categoryForm, name: e.target.value })} required />
                        <Input label="Receipt over (empty = optional)" value={categoryForm.requires_receipt_above} error={categoryErrors.requires_receipt_above} onChange={(e) => setCategoryForm({ ...categoryForm, requires_receipt_above: e.target.value })} />
                        <div className="flex items-end"><Button type="submit" variant="secondary">Add category</Button></div>
                    </form>
                </Card>
            )}

            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={`Decide ${deciding?.claim_number ?? ''}`}>
                <form onSubmit={decide} className="grid gap-3">
                    <p className="text-sm text-gray-500">Filed total {deciding?.total_amount}.</p>
                    <Select label="Verdict" value={verdict} error={decideErrors.verdict} onChange={(e) => setVerdict(e.target.value)}>
                        <option value="approve">Approve</option>
                        <option value="reject">Reject</option>
                    </Select>
                    {verdict === 'approve' && (
                        <Input label="Approved amount" value={approvedAmount} error={decideErrors.approved_amount} onChange={(e) => setApprovedAmount(e.target.value)} />
                    )}
                    <Input label={verdict === 'approve' ? 'Reason (required for a cut)' : 'Reason'} value={reason} error={decideErrors.reason} onChange={(e) => setReason(e.target.value)} />
                    <div><Button type="submit">Record decision</Button></div>
                </form>
            </Modal>
        </div>
    );
}
