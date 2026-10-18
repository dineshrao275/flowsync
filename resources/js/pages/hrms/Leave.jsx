import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { Table, Th, Td, TableEmpty } from '../../components/ui/Table';
import { useToast } from '../../context/ToastContext';
import { useConfirm, usePrompt } from '../../components/ui/ConfirmDialog';
import { useAuth } from '../../context/AuthContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import LeaveBalanceTable from '../../components/hrms/LeaveBalanceTable';
import LeaveAdjustPanel from '../../components/hrms/LeaveAdjustPanel';
import LeaveBlackoutPanel from '../../components/hrms/LeaveBlackoutPanel';
import LeaveRequestModal from '../../components/hrms/LeaveRequestModal';
import RecordComments from '../../components/hrms/RecordComments';

const TABS = [
    { key: 'requests', labelKey: 'hrms.leave.tabs.requests' },
    { key: 'types', labelKey: 'hrms.leave.tabs.types' },
    { key: 'policies', labelKey: 'hrms.leave.tabs.policies' },
    { key: 'balances', labelKey: 'hrms.leave.tabs.balances' },
    { key: 'adjustments', labelKey: 'hrms.leave.tabs.adjustments' },
    { key: 'blackouts', labelKey: 'hrms.leave.tabs.blackouts' },
    { key: 'exemptions', labelKey: 'hrms.leave.tabs.exemptions' },
];

const REQUEST_STATUSES = [
    { value: '', key: 'stAllOpen' },
    { value: 'submitted', key: 'stSubmitted' },
    { value: 'pending', key: 'stPending' },
    { value: 'approved', key: 'stApproved' },
    { value: 'rejected', key: 'stRejected' },
    { value: 'cancelled', key: 'stCancelled' },
];

const ACCRUAL_METHODS = [
    { value: 'none', key: 'accrualNone' },
    { value: 'annual', key: 'accrualAnnual' },
    { value: 'monthly', key: 'accrualMonthly' },
    { value: 'quarterly', key: 'accrualQuarterly' },
    { value: 'per_payroll', key: 'accrualPayroll' },
];

const ACCRUAL_PERIODS = [
    { value: 'monthly', key: 'periodMonthly' },
    { value: 'quarterly', key: 'periodQuarterly' },
    { value: 'biannual', key: 'periodBiannual' },
    { value: 'annual', key: 'periodAnnual' },
];

const emptyType = { name: '', code: '', is_paid: true, accrual_method: 'none', accrual_rate: 0, max_balance: '', allow_half_day: true, is_active: true };
const emptyPolicy = { name: '', accrual_period: 'annual', start_month: 1, description: '', is_default: false, is_active: true };
const emptyExemption = { employee_id: '', leave_type_id: '', from_date: '', to_date: '', days: '', reason: '', fiscal_year: '' };

/**
 * Leave administration: the queues, the catalogue, and everyone's balances.
 *
 * Route-gated on `hrms.leave.manage`, so every picker and button here may
 * assume it: the request modal files for a chosen employee, the catalogue
 * edits land directly, and the exemption queue decides in place — with the
 * verdict itself still answered by the approval chain, so a manage holder
 * who is not the current approver gets the backend's "only the assigned
 * approver" toast rather than a silent pass. The exemption tab and its
 * fetch additionally ride `hrms.leave.exemption` (the two entitlements
 * move separately), so a plan with leave but without the exemption module
 * still gets a working catalogue instead of a 403. The self-service twin
 * lives on `/hrms/leave/mine`.
 */
export default function Leave() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.leave.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();
    const confirm = useConfirm();
    const prompt = usePrompt();
    const { hasModule } = useAuth();

    // The exemption queue rides its own module (`hrms.leave.exemption`),
    // which moves independently of `hrms.leave` — fetch it only when the
    // plan carries it, or the shared catalogue load 403s the whole page.
    const showExemptions = hasModule('hrms.leave.exemption');

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

    const [filingExemption, setFilingExemption] = useState(false);
    const [exemptionForm, setExemptionForm] = useState(emptyExemption);
    const [exemptionErrors, setExemptionErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.leave.pageTitle') }]);
    }, [setCrumbs, t]);

    const fail = useCallback(
        (key) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError(t(key));
        },
        [navigate, t],
    );

    const loadRequests = useCallback(() => {
        const params = requestStatus ? { status: requestStatus } : {};

        return api
            .get('/hrms/leave/requests', { params })
            .then(({ data }) => setRequests(data.requests ?? []))
            .catch(fail('hrms.leave.loadRequestsFailed'));
    }, [requestStatus, fail]);

    const loadCatalog = useCallback(() => {
        setError(null);

        const loads = [
            api.get('/hrms/leave/types').then(({ data }) => setTypes(data.leave_types ?? [])),
            api.get('/hrms/leave/policies').then(({ data }) => setPolicies(data.leave_policies ?? [])),
        ];

        if (showExemptions) {
            loads.push(api.get('/hrms/leave/exemptions').then(({ data }) => setExemptions(data.exemptions ?? [])));
        }

        return Promise.all(loads).catch(fail('hrms.leave.loadCatalogFailed'));
    }, [fail, showExemptions]);

    const loadBalances = useCallback(() => {
        if (!balanceEmployee) {
            setBalances(null);

            return Promise.resolve();
        }

        return api
            .get('/hrms/leave/balances', { params: { employee_id: Number(balanceEmployee), year: Number(balanceYear) || undefined } })
            .then(({ data }) => setBalances(data.balances ?? []))
            .catch(fail('hrms.leave.loadBalancesFailed'));
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

            toast.success(verdict === 'approve' ? t('hrms.leave.approved') : t('hrms.leave.rejected'));
            setDeciding(null);
            setDecisionNote('');
            loadRequests();
        } catch (err) {
            if (err.response?.status === 403) {
                toast.error(t('hrms.leave.forbidden'));
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
                toast.success(t('hrms.leave.typeUpdated'));
            } else {
                await api.post('/hrms/leave/types', payload);
                toast.success(t('hrms.leave.typeCreated'));
            }

            setEditingType(null);
            loadCatalog();
        } catch (err) {
            setTypeErrors(fieldErrors(err));
        }
    }

    async function deleteType(id) {
        if (!(await confirm({ title: t('hrms.leave.deleteTypeTitle'), message: t('hrms.leave.deleteTypeMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/leave/types/${id}`);

            toast.success(t('hrms.leave.typeDeleted'));
            loadCatalog();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? t('hrms.leave.typeDeleteFailed'));
        }
    }

    async function savePolicy(e) {
        e.preventDefault();
        setPolicyErrors({});

        const payload = { ...policyForm, start_month: Number(policyForm.start_month) || 1 };

        try {
            if (editingPolicy) {
                await api.put(`/hrms/leave/policies/${editingPolicy.id}`, payload);
                toast.success(t('hrms.leave.policyUpdated'));
            } else {
                await api.post('/hrms/leave/policies', payload);
                toast.success(t('hrms.leave.policyCreated'));
            }

            setEditingPolicy(null);
            loadCatalog();
        } catch (err) {
            setPolicyErrors(fieldErrors(err));
        }
    }

    async function deletePolicy(id) {
        if (!(await confirm({ title: t('hrms.leave.deletePolicyTitle'), message: t('hrms.leave.deletePolicyMessage'), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/leave/policies/${id}`);

            toast.success(t('hrms.leave.policyDeleted'));
            loadCatalog();
        } catch (err) {
            toast.error(err.response?.data?.errors?.form?.[0] ?? t('hrms.leave.policyDeleteFailed'));
        }
    }

    async function decideExemption(id, decision) {
        const isApprove = decision === 'approve';
        const note = await prompt({
            title: isApprove ? t('hrms.leave.approveExemptionTitle') : t('hrms.leave.rejectExemptionTitle'),
            message: isApprove ? t('hrms.leave.approveNote') : t('hrms.leave.rejectReason'),
            placeholder: isApprove ? t('hrms.leave.approvePlaceholder') : t('hrms.leave.rejectPlaceholder'),
            required: !isApprove,
            confirmLabel: isApprove ? t('hrms.leave.approve') : t('hrms.leave.reject'),
            variant: isApprove ? 'primary' : 'danger',
        });

        if (note === null) return;

        try {
            await api.post(`/hrms/leave/exemptions/${id}/decide`, { decision, note: note || null });

            toast.success(decision === 'approve' ? t('hrms.leave.exemptionApproved') : t('hrms.leave.exemptionRejected'));
            loadCatalog();
        } catch (err) {
            toast.error(err.response?.data?.message ?? fieldErrors(err).decision_note ?? t('hrms.leave.decideFailed'));
        }
    }

    async function saveExemption(e) {
        e.preventDefault();
        setExemptionErrors({});

        try {
            await api.post('/hrms/leave/exemptions', {
                employee_id: Number(exemptionForm.employee_id),
                leave_type_id: Number(exemptionForm.leave_type_id),
                from_date: exemptionForm.from_date,
                to_date: exemptionForm.to_date,
                days: Number(exemptionForm.days),
                reason: exemptionForm.reason,
                fiscal_year: exemptionForm.fiscal_year ? Number(exemptionForm.fiscal_year) : null,
            });

            toast.success(t('hrms.leave.exemptionFiled'));
            setFilingExemption(false);
            setExemptionForm(emptyExemption);
            loadCatalog();
        } catch (err) {
            setExemptionErrors(fieldErrors(err));
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
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.leave.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">{t('hrms.leave.subtitle')}</p>
                </div>

                <div className="flex gap-2">
                    {(tab === 'requests' || tab === 'balances') && (
                        <Button onClick={() => setFiling(true)}>{t('hrms.leave.fileFor')}</Button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="flex gap-1 border-b border-gray-200">
                {TABS.filter((item) => item.key !== 'exemptions' || showExemptions).map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => setTab(item.key)}
                        className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${
                            tab === item.key ? 'border-[var(--accent)] text-[var(--accent-soft-text)]' : 'border-transparent text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {t(`hrms.leave.tabs.${item.key}`)}
                    </button>
                ))}
            </div>

            {tab === 'requests' && (
                <Card dense>
                    <div className="mb-3 flex items-center gap-2">
                        <Select aria-label={t('hrms.leave.statusFilter')} value={requestStatus} onChange={(e) => setRequestStatus(e.target.value)} className="w-44">
                            {REQUEST_STATUSES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(`hrms.leave.${option.key}`)}
                                </option>
                            ))}
                        </Select>
                    </div>

                    {!requests ? (
                        <ContentSkeleton size="md" />
                    ) : requests.length === 0 ? (
                        <EmptyState title={t('hrms.leave.emptyTitle')} description={t('hrms.leave.emptyHint')} />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('hrms.leave.thEmployee')}</Th>
                                    <Th>{t('hrms.leave.thDates')}</Th>
                                    <Th>{t('hrms.leave.thType')}</Th>
                                    <Th>{t('hrms.leave.thDays')}</Th>
                                    <Th>{t('hrms.leave.thStatus')}</Th>
                                    <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
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
                                                        {t('hrms.leave.approve')}
                                                    </Button>
                                                    <Button variant="secondary" onClick={() => { setDeciding({ ...request, verdict: 'reject' }); setDecisionNote(''); setDecisionErrors({}); }}>
                                                        {t('hrms.leave.reject')}
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
                    title={t('hrms.leave.typesTitle')}
                    actions={<Button onClick={() => openTypeEditor(null)}>{t('hrms.leave.newType')}</Button>}
                >
                    {!types ? (
                        <ContentSkeleton size="md" />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('hrms.leave.thName')}</Th>
                                    <Th>{t('hrms.leave.thAccrual')}</Th>
                                    <Th>{t('hrms.leave.thMax')}</Th>
                                    <Th>{t('hrms.leave.thActive')}</Th>
                                    <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
                                </tr>
                            </thead>
                            <tbody>
                                {types.map((type) => (
                                    <tr key={type.id}>
                                        <Td>
                                            <span className="font-medium">{type.name}</span>
                                            {type.is_system && <span className="ml-2 text-xs text-gray-400">{t('hrms.leave.system')}</span>}
                                        </Td>
                                        <Td>{type.accrual_method_label} · {type.accrual_rate}</Td>
                                        <Td>{type.max_balance ?? '—'}</Td>
                                        <Td>{type.is_active ? t('hrms.leave.yes') : t('hrms.leave.no')}</Td>
                                        <Td>
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openTypeEditor(type)}>{t('hrms.leave.edit')}</Button>
                                                <Button variant="secondary" onClick={() => deleteType(type.id)}>{t('hrms.leave.delete')}</Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {types.length === 0 && <TableEmpty colSpan={5}>{t('hrms.leave.emptyTypes')}</TableEmpty>}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            {tab === 'policies' && (
                <Card
                    dense
                    title={t('hrms.leave.policiesTitle')}
                    actions={<Button onClick={() => openPolicyEditor(null)}>{t('hrms.leave.newPolicy')}</Button>}
                >
                    {!policies ? (
                        <ContentSkeleton size="md" />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('hrms.leave.thName')}</Th>
                                    <Th>{t('hrms.leave.thPeriod')}</Th>
                                    <Th>{t('hrms.leave.thYearStarts')}</Th>
                                    <Th>{t('hrms.leave.thDefault')}</Th>
                                    <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
                                </tr>
                            </thead>
                            <tbody>
                                {policies.map((policy) => (
                                    <tr key={policy.id}>
                                        <Td><span className="font-medium">{policy.name}</span></Td>
                                        <Td>{policy.accrual_period_label}</Td>
                                        <Td>{t('hrms.leave.month', { month: policy.start_month })}</Td>
                                        <Td>{policy.is_default ? t('hrms.leave.yes') : t('hrms.leave.no')}</Td>
                                        <Td>
                                            <div className="flex justify-end gap-2">
                                                <Button variant="secondary" onClick={() => openPolicyEditor(policy)}>{t('hrms.leave.edit')}</Button>
                                                <Button variant="secondary" onClick={() => deletePolicy(policy.id)}>{t('hrms.leave.delete')}</Button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {policies.length === 0 && <TableEmpty colSpan={5}>{t('hrms.leave.emptyPolicies')}</TableEmpty>}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            {tab === 'balances' && (
                <Card dense title={t('hrms.leave.balancesTitle')}>
                    <div className="mb-3 flex items-center gap-2">
                        <Select aria-label={t('hrms.leave.employee')} value={balanceEmployee} onChange={(e) => setBalanceEmployee(e.target.value)} className="w-56">
                            <option value="">{t('hrms.leave.selectEmployee')}</option>
                            {employees.map((employee) => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.name}
                                </option>
                            ))}
                        </Select>
                        <Input aria-label={t('hrms.leave.year')} value={balanceYear} onChange={(e) => setBalanceYear(e.target.value)} className="w-28" />
                    </div>

                    {!balanceEmployee ? (
                        <EmptyState title={t('hrms.leave.noEmployee')} description={t('hrms.leave.noEmployeeHint')} />
                    ) : !balances ? (
                        <ContentSkeleton size="md" />
                    ) : (
                        <LeaveBalanceTable balances={balances} />
                    )}
                </Card>
            )}

            {tab === 'adjustments' && <LeaveAdjustPanel employees={employees} types={types ?? []} />}
            {tab === 'blackouts' && <LeaveBlackoutPanel types={types ?? []} />}

            {tab === 'exemptions' && (
                <>
                    <div className="flex justify-end">
                        <Button
                            onClick={() => {
                                setExemptionForm(emptyExemption);
                                setExemptionErrors({});
                                setFilingExemption(true);
                            }}
                        >
                            {t('hrms.leave.fileExemption')}
                        </Button>
                    </div>
                    <Card dense title={t('hrms.leave.exemptionsTitle')}>
                    {!exemptions ? (
                        <ContentSkeleton size="md" />
                    ) : exemptions.length === 0 ? (
                        <EmptyState title={t('hrms.leave.exemptionsEmptyTitle')} description={t('hrms.leave.exemptionsEmptyHint')} />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('hrms.leave.thEmployee')}</Th>
                                    <Th>{t('hrms.leave.thDates')}</Th>
                                    <Th>{t('hrms.leave.thDays')}</Th>
                                    <Th>{t('hrms.leave.thStatus')}</Th>
                                    <Th><span className="sr-only">{t('hrms.leave.thActions')}</span></Th>
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
                                                    <Button variant="secondary" onClick={() => decideExemption(exemption.id, 'approve')}>{t('hrms.leave.approve')}</Button>
                                                    <Button variant="secondary" onClick={() => decideExemption(exemption.id, 'reject')}>{t('hrms.leave.reject')}</Button>
                                                </div>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
                </>
            )}

            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={deciding?.verdict === 'approve' ? t('hrms.leave.decideApproveTitle') : t('hrms.leave.decideRejectTitle')}>
                <div className="space-y-3 px-6 py-5">
                    <p className="text-sm text-gray-500">
                        {deciding?.verdict === 'approve'
                            ? t('hrms.leave.decideApproveIntro')
                            : t('hrms.leave.decideRejectIntro')}
                    </p>
                    <Input label={deciding?.verdict === 'approve' ? t('hrms.leave.noteOptional') : t('hrms.leave.reason')} value={decisionNote} onChange={(e) => setDecisionNote(e.target.value)} error={decisionErrors.note ?? decisionErrors.decision_note} />
                    {decisionErrors.form && <Alert>{decisionErrors.form}</Alert>}
                    {deciding?.id && <RecordComments type="leave" id={deciding.id} />}
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeciding(null)}>{t('hrms.leave.cancel')}</Button>
                        <Button onClick={() => decide(deciding.verdict)}>
                            {deciding?.verdict === 'approve' ? t('hrms.leave.approve') : t('hrms.leave.reject')}
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal open={!!editingType} onClose={() => setEditingType(null)} title={editingType ? t('hrms.leave.editTypeTitle') : t('hrms.leave.newTypeTitle')}>
                <form
                    onSubmit={saveType}
                    className="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-2"
                >
                    <Input label={t('hrms.leave.typeName')} value={typeForm.name} onChange={(e) => setTypeForm({ ...typeForm, name: e.target.value })} error={typeErrors.name} />
                    <Input label={t('hrms.leave.typeCode')} value={typeForm.code} onChange={(e) => setTypeForm({ ...typeForm, code: e.target.value })} error={typeErrors.code} />
                    <Select label={t('hrms.leave.accrual')} value={typeForm.accrual_method} onChange={(e) => setTypeForm({ ...typeForm, accrual_method: e.target.value })} error={typeErrors.accrual_method}>
                        {ACCRUAL_METHODS.map((option) => (
                            <option key={option.value} value={option.value}>{t(`hrms.leave.${option.key}`)}</option>
                        ))}
                    </Select>
                    <Input label={t('hrms.leave.accrualRate')} type="number" step="0.001" value={typeForm.accrual_rate} onChange={(e) => setTypeForm({ ...typeForm, accrual_rate: e.target.value })} error={typeErrors.accrual_rate} />
                    <Input label={t('hrms.leave.maxBalance')} type="number" step="0.01" value={typeForm.max_balance} onChange={(e) => setTypeForm({ ...typeForm, max_balance: e.target.value })} error={typeErrors.max_balance} />
                    <Select label={t('hrms.leave.paid')} value={String(typeForm.is_paid)} onChange={(e) => setTypeForm({ ...typeForm, is_paid: e.target.value === 'true' })} error={typeErrors.is_paid}>
                        <option value="true">{t('hrms.leave.yes')}</option>
                        <option value="false">{t('hrms.leave.no')}</option>
                    </Select>
                    <div className="sm:col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setEditingType(null)}>{t('hrms.leave.cancel')}</Button>
                        <Button type="submit">{t('hrms.leave.save')}</Button>
                    </div>
                    {typeErrors.form && <Alert>{typeErrors.form}</Alert>}
                </form>
            </Modal>

            <Modal open={!!editingPolicy} onClose={() => setEditingPolicy(null)} title={editingPolicy ? t('hrms.leave.editPolicyTitle') : t('hrms.leave.newPolicyTitle')}>
                <form
                    onSubmit={savePolicy}
                    className="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-2"
                >
                    <Input label={t('hrms.leave.typeName')} value={policyForm.name} onChange={(e) => setPolicyForm({ ...policyForm, name: e.target.value })} error={policyErrors.name} />
                    <Select label={t('hrms.leave.accrualPeriod')} value={policyForm.accrual_period} onChange={(e) => setPolicyForm({ ...policyForm, accrual_period: e.target.value })} error={policyErrors.accrual_period}>
                        {ACCRUAL_PERIODS.map((option) => (
                            <option key={option.value} value={option.value}>{t(`hrms.leave.${option.key}`)}</option>
                        ))}
                    </Select>
                    <Input label={t('hrms.leave.yearStarts')} type="number" min="1" max="12" value={policyForm.start_month} onChange={(e) => setPolicyForm({ ...policyForm, start_month: e.target.value })} error={policyErrors.start_month} />
                    <Input label={t('hrms.leave.policyDesc')} value={policyForm.description} onChange={(e) => setPolicyForm({ ...policyForm, description: e.target.value })} error={policyErrors.description} />
                    <div className="sm:col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setEditingPolicy(null)}>{t('hrms.leave.cancel')}</Button>
                        <Button type="submit">{t('hrms.leave.save')}</Button>
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

            <Modal open={filingExemption} onClose={() => setFilingExemption(false)} title={t('hrms.leave.fileExemptionTitle')}>
                <form onSubmit={saveExemption} className="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-2">
                    <Select label={t('hrms.leave.exemptionEmployee')} value={exemptionForm.employee_id} onChange={(e) => setExemptionForm({ ...exemptionForm, employee_id: e.target.value })} error={exemptionErrors.employee_id}>
                        <option value="">{t('hrms.leave.selectPerson')}</option>
                        {employees.map((employee) => (
                            <option key={employee.id} value={employee.id}>{employee.name}</option>
                        ))}
                    </Select>
                    <Select label={t('hrms.leave.exemptionType')} value={exemptionForm.leave_type_id} onChange={(e) => setExemptionForm({ ...exemptionForm, leave_type_id: e.target.value })} error={exemptionErrors.leave_type_id}>
                        <option value="">{t('hrms.leave.selectType')}</option>
                        {(types ?? []).map((type) => (
                            <option key={type.id} value={type.id}>{type.name}</option>
                        ))}
                    </Select>
                    <Input label={t('hrms.leave.from')} type="date" value={exemptionForm.from_date} onChange={(e) => setExemptionForm({ ...exemptionForm, from_date: e.target.value })} error={exemptionErrors.from_date} />
                    <Input label={t('hrms.leave.to')} type="date" value={exemptionForm.to_date} onChange={(e) => setExemptionForm({ ...exemptionForm, to_date: e.target.value })} error={exemptionErrors.to_date} />
                    <Input label={t('hrms.leave.days')} type="number" step="0.5" min="0.5" value={exemptionForm.days} onChange={(e) => setExemptionForm({ ...exemptionForm, days: e.target.value })} error={exemptionErrors.days} />
                    <Input label={t('hrms.leave.fiscalYear')} type="number" placeholder={String(new Date().getFullYear())} value={exemptionForm.fiscal_year} onChange={(e) => setExemptionForm({ ...exemptionForm, fiscal_year: e.target.value })} error={exemptionErrors.fiscal_year} />
                    <div className="sm:col-span-2">
                        <Input label={t('hrms.leave.reason')} value={exemptionForm.reason} onChange={(e) => setExemptionForm({ ...exemptionForm, reason: e.target.value })} error={exemptionErrors.reason} />
                    </div>
                    <div className="sm:col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setFilingExemption(false)}>{t('hrms.leave.cancel')}</Button>
                        <Button type="submit">{t('hrms.leave.fileExemptionSubmit')}</Button>
                    </div>
                    {exemptionErrors.form && <Alert>{exemptionErrors.form}</Alert>}
                </form>
            </Modal>
        </div>
    );
}
