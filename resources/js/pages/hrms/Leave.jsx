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
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import LeaveBalanceTable from '../../components/hrms/LeaveBalanceTable';
import LeaveRequestModal from '../../components/hrms/LeaveRequestModal';

const TABS = [
    { key: 'requests', label: 'Requests' },
    { key: 'types', label: 'Types' },
    { key: 'policies', label: 'Policies' },
    { key: 'balances', label: 'Balances' },
    { key: 'exemptions', label: 'Exemptions' },
];

const REQUEST_STATUSES = [
    { value: '', label: 'All open' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Cancelled' },
];

const ACCRUAL_METHODS = [
    { value: 'none', label: 'No accrual' },
    { value: 'annual', label: 'Annual' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'per_payroll', label: 'Per payroll' },
];

const ACCRUAL_PERIODS = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'biannual', label: 'Biannual' },
    { value: 'annual', label: 'Annual' },
];

const emptyType = { name: '', code: '', is_paid: true, accrual_method: 'none', accrual_rate: 0, max_balance: '', allow_half_day: true, is_active: true };
const emptyPolicy = { name: '', accrual_period: 'annual', start_month: 1, description: '', is_default: false, is_active: true };

/**
 * Leave administration: the queues, the catalogue, and everyone's balances.
 *
 * Route-gated on `hrms.leave.manage`, so every picker and button here may
 * assume it: the request modal files for a chosen employee, the catalogue
 * edits land directly, and the exemption queue decides in place. The
 * self-service twin lives on `/hrms/leave/mine`.
 */
export default function Leave() {
    usePageTitle('Leave');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [tab, setTab] = useState('requests');
    const [error, setError] = useState(null);

    const [requests, setRequests] = useState(null);
    const [requestStatus, setRequestStatus] = useState('');
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

    const [employees, setEmployees] = useState([]);
    const [balanceEmployee, setBalanceEmployee] = useState('');
    const [balanceYear, setBalanceYear] = useState(String(new Date().getFullYear()));
    const [balances, setBalances] = useState(null);

    const [exemptions, setExemptions] = useState(null);
    const [filing, setFiling] = useState(false);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Leave' }]);
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

    const loadRequests = useCallback(() => {
        const params = requestStatus ? { status: requestStatus } : {};

        return api
            .get('/hrms/leave/requests', { params })
            .then(({ data }) => setRequests(data.requests ?? []))
            .catch(fail('Unable to load leave requests.'));
    }, [requestStatus, fail]);

    const loadCatalog = useCallback(() => {
        setError(null);

        return Promise.all([
            api.get('/hrms/leave/types').then(({ data }) => setTypes(data.leave_types ?? [])),
            api.get('/hrms/leave/policies').then(({ data }) => setPolicies(data.leave_policies ?? [])),
            api.get('/hrms/leave/exemptions').then(({ data }) => setExemptions(data.exemptions ?? [])),
        ]).catch(fail('Unable to load the leave catalogue.'));
    }, [fail]);

    const loadBalances = useCallback(() => {
        if (!balanceEmployee) {
            setBalances(null);

            return Promise.resolve();
        }

        return api
            .get('/hrms/leave/balances', { params: { employee_id: Number(balanceEmployee), year: Number(balanceYear) || undefined } })
            .then(({ data }) => setBalances(data.balances ?? []))
            .catch(fail('Unable to load balances.'));
    }, [balanceEmployee, balanceYear, fail]);

    useEffect(() => {
        loadRequests();
    }, [loadRequests]);

    useEffect(() => {
        loadCatalog();

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        loadBalances();
    }, [loadBalances]);

    async function decide(verdict) {
        if (!deciding) return;

        setDecisionErrors({});

        try {
            await api.post(`/hrms/leave/requests/${deciding.id}/${verdict}`, { note: decisionNote || null });

            toast.success(verdict === 'approve' ? 'Leave approved.' : 'Leave rejected.');
            setDeciding(null);
            setDecisionNote('');
            loadRequests();
        } catch (err) {
            if (err.response?.status === 403) {
                toast.error('Only the assigned approver can decide this request.');
                return;
            }

            setDecisionErrors(fieldErrors(err));
        }
    }

    async function saveType(e) {
        e.preventDefault();
        setTypeErrors({});

        const payload = {
            ...typeForm,
            accrual_rate: Number(typeForm.accrual_rate) || 0,
            max_balance: typeForm.max_balance === '' ? null : Number(typeForm.max_balance),
        };

        try {
            if (editingType) {
                await api.put(`/hrms/leave/types/${editingType.id}`, payload);
                toast.success('Leave type updated.');
            } else {
                await api.post('/hrms/leave/types', payload);
                toast.success('Leave type created.');
            }

            setEditingType(null);
            loadCatalog();
        } catch (err) {
            setTypeErrors(fieldErrors(err));
        }
    }

    async function deleteType(id) {
        if (!window.confirm('Delete this leave type? Types in use refuse deletion.')) return;

        try {
            await api.delete(`/hrms/leave/types/${id}`);

            toast.success('Leave type deleted.');
            loadCatalog();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? 'Unable to delete the type.');
        }
    }

    async function savePolicy(e) {
        e.preventDefault();
        setPolicyErrors({});

        const payload = { ...policyForm, start_month: Number(policyForm.start_month) || 1 };

        try {
            if (editingPolicy) {
                await api.put(`/hrms/leave/policies/${editingPolicy.id}`, payload);
                toast.success('Leave policy updated.');
            } else {
                await api.post('/hrms/leave/policies', payload);
                toast.success('Leave policy created.');
            }

            setEditingPolicy(null);
            loadCatalog();
        } catch (err) {
            setPolicyErrors(fieldErrors(err));
        }
    }

    async function deletePolicy(id) {
        if (!window.confirm('Delete this leave policy?')) return;

        try {
            await api.delete(`/hrms/leave/policies/${id}`);

            toast.success('Leave policy deleted.');
            loadCatalog();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? 'Unable to delete the policy.');
        }
    }

    async function decideExemption(id, decision) {
        const note = window.prompt(decision === 'approve' ? 'Approval note (optional):' : 'Rejection reason (required):');

        if (note === null) return;

        try {
            await api.post(`/hrms/leave/exemptions/${id}/decide`, { decision, note: note || null });

            toast.success(decision === 'approve' ? 'Exemption approved.' : 'Exemption rejected.');
            loadCatalog();
        } catch (err) {
            toast.error(err.response?.data?.message ?? fieldErrors(err).decision_note ?? 'Unable to decide.');
        }
    }

    function openTypeEditor(type) {
        setEditingType(type ?? null);
        setTypeForm(type ? { ...emptyType, ...type } : emptyType);
        setTypeErrors({});
    }

    function openPolicyEditor(policy) {
        setEditingPolicy(policy ?? null);
        setPolicyForm(policy ? { ...emptyPolicy, ...policy } : emptyPolicy);
        setPolicyErrors({});
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Leave</h2>
                    <p className="mt-0.5 text-sm text-gray-500">Requests, catalogue, balances, exemptions.</p>
                </div>

                <div className="flex gap-2">
                    {(tab === 'requests' || tab === 'balances') && (
                        <Button onClick={() => setFiling(true)}>File for employee</Button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="flex gap-1 border-b border-gray-200">
                {TABS.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => setTab(item.key)}
                        className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${
                            tab === item.key ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            {tab === 'requests' && (
                <Card dense>
                    <div className="mb-3 flex items-center gap-2">
                        <Select aria-label="Status filter" value={requestStatus} onChange={(e) => setRequestStatus(e.target.value)} className="w-44">
                            {REQUEST_STATUSES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>

                    {!requests ? (
                        <div className="flex justify-center py-10"><Spinner /></div>
                    ) : requests.length === 0 ? (
                        <EmptyState title="No requests" description="Nothing filed under this filter." />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Employee</Th>
                                    <Th>Dates</Th>
                                    <Th>Type</Th>
                                    <Th>Days</Th>
                                    <Th>Status</Th>
                                    <Th><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.map((request) => (
                                    <tr key={request.id}>
                                        <Td>{request.employee?.name ?? '—'}</Td>
                                        <Td>{request.from_date} → {request.to_date}</Td>
                                        <Td>{request.type?.name ?? '—'}</Td>
                                        <Td>{request.total_days}</Td>
                                        <Td>{request.status_label}</Td>
                                        <Td>
                                            {request.status === 'submitted' || request.status === 'pending' ? (
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="secondary" onClick={() => { setDeciding({ ...request, verdict: 'approve' }); setDecisionNote(''); setDecisionErrors({}); }}>
                                                        Approve
                                                    </Button>
                                                    <Button variant="secondary" onClick={() => { setDeciding({ ...request, verdict: 'reject' }); setDecisionNote(''); setDecisionErrors({}); }}>
                                                        Reject
                                                    </Button>
                                                </div>
                                            ) : null}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            {tab === 'types' && (
                <Card
                    dense
                    title="Leave types"
                    actions={<Button onClick={() => openTypeEditor(null)}>New type</Button>}
                >
                    {!types ? (
                        <div className="flex justify-center py-10"><Spinner /></div>
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Name</Th>
                                    <Th>Accrual</Th>
                                    <Th>Max</Th>
                                    <Th>Active</Th>
                                    <Th><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody>
                                {types.map((type) => (
                                    <tr key={type.id}>
                                        <Td>
                                            <span className="font-medium">{type.name}</span>
                                            {type.is_system && <span className="ml-2 text-xs text-gray-400">system</span>}
                                        </Td>
                                        <Td>{type.accrual_method_label} · {type.accrual_rate}</Td>
                                        <Td>{type.max_balance ?? '—'}</Td>
                                        <Td>{type.is_active ? 'Yes' : 'No'}</Td>
                                        <Td>
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openTypeEditor(type)}>Edit</Button>
                                                <Button variant="secondary" onClick={() => deleteType(type.id)}>Delete</Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {types.length === 0 && <TableEmpty colSpan={5}>No leave types yet.</TableEmpty>}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            {tab === 'policies' && (
                <Card
                    dense
                    title="Leave policies"
                    actions={<Button onClick={() => openPolicyEditor(null)}>New policy</Button>}
                >
                    {!policies ? (
                        <div className="flex justify-center py-10"><Spinner /></div>
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Name</Th>
                                    <Th>Period</Th>
                                    <Th>Year starts</Th>
                                    <Th>Default</Th>
                                    <Th><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody>
                                {policies.map((policy) => (
                                    <tr key={policy.id}>
                                        <Td><span className="font-medium">{policy.name}</span></Td>
                                        <Td>{policy.accrual_period_label}</Td>
                                        <Td>Month {policy.start_month}</Td>
                                        <Td>{policy.is_default ? 'Yes' : 'No'}</Td>
                                        <Td>
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openPolicyEditor(policy)}>Edit</Button>
                                                <Button variant="secondary" onClick={() => deletePolicy(policy.id)}>Delete</Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {policies.length === 0 && <TableEmpty colSpan={5}>No leave policies yet.</TableEmpty>}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            {tab === 'balances' && (
                <Card dense title="Balances">
                    <div className="mb-3 flex items-center gap-2">
                        <Select aria-label="Employee" value={balanceEmployee} onChange={(e) => setBalanceEmployee(e.target.value)} className="w-56">
                            <option value="">Select an employee…</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.name}
                                </option>
                            ))}
                        </Select>
                        <Input aria-label="Year" value={balanceYear} onChange={(e) => setBalanceYear(e.target.value)} className="w-28" />
                    </div>

                    {!balanceEmployee ? (
                        <EmptyState title="No employee selected" description="Pick an employee to see their balances." />
                    ) : !balances ? (
                        <div className="flex justify-center py-10"><Spinner /></div>
                    ) : (
                        <LeaveBalanceTable balances={balances} />
                    )}
                </Card>
            )}

            {tab === 'exemptions' && (
                <Card dense title="Exemptions">
                    {!exemptions ? (
                        <div className="flex justify-center py-10"><Spinner /></div>
                    ) : exemptions.length === 0 ? (
                        <EmptyState title="No exemptions" description="No statutory exemption asks filed." />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Employee</Th>
                                    <Th>Dates</Th>
                                    <Th>Days</Th>
                                    <Th>Status</Th>
                                    <Th><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody>
                                {exemptions.map((exemption) => (
                                    <tr key={exemption.id}>
                                        <Td>{exemption.employee?.name ?? '—'}</Td>
                                        <Td>{exemption.from_date} → {exemption.to_date}</Td>
                                        <Td>{exemption.days}</Td>
                                        <Td>{exemption.status_label}</Td>
                                        <Td>
                                            {exemption.status === 'pending' && (
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="secondary" onClick={() => decideExemption(exemption.id, 'approve')}>Approve</Button>
                                                    <Button variant="secondary" onClick={() => decideExemption(exemption.id, 'reject')}>Reject</Button>
                                                </div>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={deciding?.verdict === 'approve' ? 'Approve leave' : 'Reject leave'}>
                <div className="space-y-3">
                    <p className="text-sm text-gray-500">
                        {deciding?.verdict === 'approve'
                            ? 'Approval advances the chain; the posting lands when it resolves.'
                            : 'A rejection needs a reason the requester can act on.'}
                    </p>
                    <Input label={deciding?.verdict === 'approve' ? 'Note (optional)' : 'Reason'} value={decisionNote} onChange={(e) => setDecisionNote(e.target.value)} error={decisionErrors.note ?? decisionErrors.decision_note} />
                    {decisionErrors.form && <Alert>{decisionErrors.form}</Alert>}
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeciding(null)}>Cancel</Button>
                        <Button onClick={() => decide(deciding.verdict)}>
                            {deciding?.verdict === 'approve' ? 'Approve' : 'Reject'}
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal open={!!editingType} onClose={() => setEditingType(null)} title={editingType ? 'Edit leave type' : 'New leave type'}>
                <form
                    onSubmit={saveType}
                    className="grid grid-cols-1 gap-3 sm:grid-cols-2"
                >
                    <Input label="Name" value={typeForm.name} onChange={(e) => setTypeForm({ ...typeForm, name: e.target.value })} error={typeErrors.name} />
                    <Input label="Code" value={typeForm.code} onChange={(e) => setTypeForm({ ...typeForm, code: e.target.value })} error={typeErrors.code} />
                    <Select label="Accrual" value={typeForm.accrual_method} onChange={(e) => setTypeForm({ ...typeForm, accrual_method: e.target.value })} error={typeErrors.accrual_method}>
                        {ACCRUAL_METHODS.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </Select>
                    <Input label="Accrual rate" type="number" step="0.001" value={typeForm.accrual_rate} onChange={(e) => setTypeForm({ ...typeForm, accrual_rate: e.target.value })} error={typeErrors.accrual_rate} />
                    <Input label="Max balance" type="number" step="0.01" value={typeForm.max_balance} onChange={(e) => setTypeForm({ ...typeForm, max_balance: e.target.value })} error={typeErrors.max_balance} />
                    <Select label="Paid" value={String(typeForm.is_paid)} onChange={(e) => setTypeForm({ ...typeForm, is_paid: e.target.value === 'true' })} error={typeErrors.is_paid}>
                        <option value="true">Yes</option>
                        <option value="false">No</option>
                    </Select>
                    <div className="sm:col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setEditingType(null)}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                    {typeErrors.form && <Alert>{typeErrors.form}</Alert>}
                </form>
            </Modal>

            <Modal open={!!editingPolicy} onClose={() => setEditingPolicy(null)} title={editingPolicy ? 'Edit leave policy' : 'New leave policy'}>
                <form
                    onSubmit={savePolicy}
                    className="grid grid-cols-1 gap-3 sm:grid-cols-2"
                >
                    <Input label="Name" value={policyForm.name} onChange={(e) => setPolicyForm({ ...policyForm, name: e.target.value })} error={policyErrors.name} />
                    <Select label="Accrual period" value={policyForm.accrual_period} onChange={(e) => setPolicyForm({ ...policyForm, accrual_period: e.target.value })} error={policyErrors.accrual_period}>
                        {ACCRUAL_PERIODS.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </Select>
                    <Input label="Year starts (month)" type="number" min="1" max="12" value={policyForm.start_month} onChange={(e) => setPolicyForm({ ...policyForm, start_month: e.target.value })} error={policyErrors.start_month} />
                    <Input label="Description" value={policyForm.description} onChange={(e) => setPolicyForm({ ...policyForm, description: e.target.value })} error={policyErrors.description} />
                    <div className="sm:col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setEditingPolicy(null)}>Cancel</Button>
                        <Button type="submit">Save</Button>
                    </div>
                    {policyErrors.form && <Alert>{policyErrors.form}</Alert>}
                </form>
            </Modal>

            <LeaveRequestModal
                open={filing}
                onClose={() => setFiling(false)}
                employees={employees}
                types={types ?? []}
                onSaved={() => {
                    setFiling(false);
                    loadRequests();
                }}
            />
        </div>
    );
}
