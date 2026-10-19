import { useCallback, useEffect, useState } from 'react';
import ListSavedFilters from '../../components/ui/ListSavedFilters';
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
import { Table, Th, Td } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import RecordComments from '../../components/hrms/RecordComments';

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
    const { t } = useTranslation();
    usePageTitle(t('hrms.expenses.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();

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

    const [categoryForm, setCategoryForm] = useState({ name: '', requires_receipt_above: '', max_per_line: '', monthly_limit: '' });
    const [categoryErrors, setCategoryErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.expenses.pageTitle') }]);
    }, [setCrumbs, t]);

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
        ]).catch(fail(t('hrms.expenses.loadFailed')));
    }, [filters, fail, t]);

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
            toast.success(verdict === 'approve' ? t('hrms.expenses.claimApproved') : t('hrms.expenses.claimRejected'));
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
                max_per_line: categoryForm.max_per_line === '' ? null : categoryForm.max_per_line,
                monthly_limit: categoryForm.monthly_limit === '' ? null : categoryForm.monthly_limit,
            });
            toast.success(t('hrms.expenses.categoryCreated'));
            setCategoryForm({ name: '', requires_receipt_above: '', max_per_line: '', monthly_limit: '' });
            load();
        } catch (err) {
            setCategoryErrors(fieldErrors(err));
        }
    }

    async function deleteCategory(category) {
        if (!(await confirm({ title: t('hrms.expenses.deleteCategoryTitle'), message: t('hrms.expenses.deleteCategoryMessage', { name: category.name }), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/expenses/categories/${category.id}`);
            toast.success(t('hrms.expenses.categoryDeleted'));
            load();
        } catch {
            setError(t('hrms.expenses.categoryDeleteFailed'));
        }
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.expenses.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {claims ? (claims.length === 1 ? t('hrms.expenses.claimCountOne') : t('hrms.expenses.claimCount', { count: claims.length })) : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Select label={t('hrms.expenses.status')} value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                        {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? t('hrms.expenses.allStatuses') : s}</option>)}
                    </Select>
                    <Input label={t('hrms.expenses.year')} value={filters.period_year} onChange={(e) => setFilters({ ...filters, period_year: e.target.value })} />
                    <Input label={t('hrms.expenses.month')} value={filters.period_month} onChange={(e) => setFilters({ ...filters, period_month: e.target.value })} />
                    <div className="flex items-end">
                        <ListSavedFilters page="hrms-expenses" value={filters} onApply={(saved) => setFilters({ status: '', period_year: '', period_month: '', ...saved })} />
                    </div>
                </div>
            </Card>

            {!claims ? (
                <ContentSkeleton size="md" />
            ) : claims.length === 0 ? (
                <EmptyState title={t('hrms.expenses.emptyTitle')} hint={t('hrms.expenses.emptyHint')} />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>{t('hrms.expenses.claim')}</Th><Th>{t('hrms.expenses.employee')}</Th><Th>{t('hrms.expenses.period')}</Th><Th>{t('hrms.expenses.total')}</Th><Th>{t('hrms.expenses.approved')}</Th><Th>{t('hrms.expenses.status')}</Th>{canDecide && <Th><span className="sr-only">{t('hrms.common.actions')}</span></Th>}</tr>
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
                                                <Button size="sm" variant="secondary" onClick={() => openDecide(claim)}>{t('hrms.expenses.decide')}</Button>
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
                <Card title={t('hrms.expenses.categories')} dense>
                    <ul className="mb-3 divide-y divide-gray-100 text-sm">
                        {categories.map((category) => (
                            <li key={category.id} className="flex items-center justify-between gap-2 py-1.5">
                                <span>
                                    {category.name}
                                    <span className="ml-2 text-xs text-gray-400">
                                        {category.is_system ? t('hrms.expenses.starter') : ''}{category.requires_receipt_above === null ? t('hrms.expenses.receiptOptional') : t('hrms.expenses.receiptOver', { amount: category.requires_receipt_above })}{category.max_per_line ? ` · ${t('hrms.expenses.maxPerLineShort', { amount: category.max_per_line })}` : ''}{category.monthly_limit ? ` · ${t('hrms.expenses.monthlyShort', { amount: category.monthly_limit })}` : ''}
                                    </span>
                                </span>
                                <Button size="sm" variant="danger" onClick={() => deleteCategory(category)}>{t('hrms.common.delete')}</Button>
                            </li>
                        ))}
                    </ul>
                    <form onSubmit={saveCategory} className="grid gap-3 sm:grid-cols-3">
                        <Input label={t('hrms.expenses.name')} value={categoryForm.name} error={categoryErrors.name} onChange={(e) => setCategoryForm({ ...categoryForm, name: e.target.value })} required />
                        <Input label={t('hrms.expenses.receiptOverLabel')} value={categoryForm.requires_receipt_above} error={categoryErrors.requires_receipt_above} onChange={(e) => setCategoryForm({ ...categoryForm, requires_receipt_above: e.target.value })} />
                        <Input label={t('hrms.expenses.maxPerLine')} value={categoryForm.max_per_line} error={categoryErrors.max_per_line} onChange={(e) => setCategoryForm({ ...categoryForm, max_per_line: e.target.value })} />
                        <Input label={t('hrms.expenses.monthlyLimit')} value={categoryForm.monthly_limit} error={categoryErrors.monthly_limit} onChange={(e) => setCategoryForm({ ...categoryForm, monthly_limit: e.target.value })} />
                        <div className="flex items-end"><Button type="submit" variant="secondary">{t('hrms.expenses.addCategory')}</Button></div>
                    </form>
                </Card>
            )}

            <Modal open={!!deciding} onClose={() => setDeciding(null)} title={t('hrms.expenses.decideTitle', { number: deciding?.claim_number ?? '' })}>
                <form onSubmit={decide} className="grid gap-3 px-6 py-5">
                    <p className="text-sm text-gray-500">{t('hrms.expenses.filedTotal', { amount: deciding?.total_amount })}</p>
                    <Select label={t('hrms.expenses.verdict')} value={verdict} error={decideErrors.verdict} onChange={(e) => setVerdict(e.target.value)}>
                        <option value="approve">{t('hrms.expenses.approve')}</option>
                        <option value="reject">{t('hrms.expenses.reject')}</option>
                    </Select>
                    {verdict === 'approve' && (
                        <Input label={t('hrms.expenses.approvedAmount')} value={approvedAmount} error={decideErrors.approved_amount} onChange={(e) => setApprovedAmount(e.target.value)} />
                    )}
                    <Input label={verdict === 'approve' ? t('hrms.expenses.reasonForCut') : t('hrms.expenses.reason')} value={reason} error={decideErrors.reason} onChange={(e) => setReason(e.target.value)} />
                    <div><Button type="submit">{t('hrms.expenses.recordDecision')}</Button></div>
                    {deciding && <RecordComments type="expense" id={deciding.id} />}
                </form>
            </Modal>
        </div>
    );
}
