import { useCallback, useEffect, useState } from 'react';
import ListSavedFilters from '../../components/ui/ListSavedFilters';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Pagination from '../../components/ui/Pagination';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm, usePrompt } from '../../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { employeeUrl } from '../../utils/deepLinks';
import DocumentList from '../../components/hrms/DocumentList';
import DocumentUploader from '../../components/hrms/DocumentUploader';

const FILTER_DEFAULTS = { q: '', document_type_id: '', status: '' };

const STATUSES = [
    { value: 'pending', key: 'stPending' },
    { value: 'verified', key: 'stVerified' },
    { value: 'rejected', key: 'stRejected' },
    { value: 'expired', key: 'stExpired' },
];

/**
 * The document store: every visible file, the expiring-soon warnings, and
 * bulk verify for the reviewer working through a pile of uploads.
 *
 * Listing and verifying are different permissions on purpose — a reviewer who
 * may read the store cannot approve what is in it — so the action buttons
 * render only for `hrms.documents.manage`. The backend 403s regardless; these
 * flags only decide what gets drawn.
 */
export default function Documents() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.docs.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const prompt = usePrompt();

    const [filters, setFilters] = useState(FILTER_DEFAULTS);
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [types, setTypes] = useState([]);
    const [employees, setEmployees] = useState(null);
    const [expiring, setExpiring] = useState(null);
    const [error, setError] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [verifyingAll, setVerifyingAll] = useState(false);

    const canManage = can('permission:hrms.documents.manage');
    const canPickEmployee = can('permission:hrms.employees.view');

    useEffect(() => {
        setCrumbs([{ label: t('hrms.overview.pageTitle'), to: '/hrms' }, { label: t('hrms.docs.pageTitle') }]);
    }, [setCrumbs, t]);

    const load = useCallback(
        (targetPage, activeFilters) => {
            setError(null);

            const params = { page: targetPage, per_page: 20 };

            Object.entries(activeFilters).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) params[key] = value;
            });

            return api
                .get('/hrms/documents', { params })
                .then(({ data: response }) => setData(response))
                .catch((err) => {
                    if (err.response?.status === 403) {
                        navigate('/403', { replace: true });
                        return;
                    }

                    setError(t('hrms.docs.loadFailed'));
                });
        },
        [navigate, t],
    );

     
    useEffect(() => {
        load(page, filters);
    }, [page, filters, load]);

    useEffect(() => {
        api.get('/hrms/documents/types')
            .then(({ data }) => setTypes(data.document_types ?? []))
            .catch(() => setTypes([]));

        api.get('/hrms/documents/expiring', { params: { days: 30 } })
            .then(({ data }) => setExpiring(data.documents ?? []))
            .catch(() => setExpiring([]));

        if (canPickEmployee) {
            api.get('/hrms/employees', { params: { per_page: 250 } })
                .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name, email: e.user?.email }))))
                .catch(() => setEmployees([]));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function setFilter(key, value) {
        setPage(1);
        setFilters((f) => ({ ...f, [key]: value }));
    }

    async function verify(document) {
        try {
            await api.post(`/hrms/documents/${document.id}/verify`);
            toast.success(t('hrms.docs.verified', { title: document.title }));
            await load(page, filters);
        } catch {
            toast.error(t('hrms.docs.verifyFailed'));
        }
    }

    async function reject(document) {
        const reason = await prompt({
            title: t('hrms.docs.rejectTitle'),
            message: t('hrms.docs.rejectMessage', { title: document.title }),
            placeholder: t('hrms.docs.rejectPlaceholder'),
            required: true,
            confirmLabel: t('hrms.docs.rejectConfirm'),
            variant: 'danger',
        });

        if (reason === null) return;

        try {
            await api.post(`/hrms/documents/${document.id}/reject`, { reason });
            toast.success(t('hrms.docs.rejected', { title: document.title }));
            await load(page, filters);
        } catch (err) {
            toast.error(err.response?.data?.errors?.reason ?? t('hrms.docs.rejectFailed'));
        }
    }

    async function remove(document) {
        if (!(await confirm({ title: t('hrms.docs.deleteTitle'), message: t('hrms.docs.deleteMessage', { title: document.title }), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/documents/${document.id}`);
            toast.success(t('hrms.docs.deleted'));
            await load(page, filters);
        } catch {
            toast.error(t('hrms.docs.deleteFailed'));
        }
    }

    async function verifyAll() {
        const pending = (data?.documents ?? []).filter((document) => document.status === 'pending');

        if (pending.length === 0) return;
        if (!(await confirm({ title: t('hrms.docs.verifyAllTitle'), message: pending.length === 1 ? t('hrms.docs.verifyAllMessageOne') : t('hrms.docs.verifyAllMessage', { count: pending.length }) }))) return;

        setVerifyingAll(true);

        try {
            for (const document of pending) {
                // Sequential, not parallel: each verify is a state transition
                // with its own audit row, and a burst of them is how two
                // reviewers approve the same pile twice without noticing.
                 
                await api.post(`/hrms/documents/${document.id}/verify`);
            }

            toast.success(pending.length === 1 ? t('hrms.docs.verifiedOne') : t('hrms.docs.verifiedMany', { count: pending.length }));
            await load(page, filters);
        } catch {
            toast.error(t('hrms.docs.verifyAllFailed'));
            await load(page, filters);
        } finally {
            setVerifyingAll(false);
        }
    }

    const rows = data?.documents ?? null;
    const pendingCount = (rows ?? []).filter((document) => document.status === 'pending').length;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">{t('hrms.docs.pageTitle')}</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? (data.pagination.total === 1 ? t('hrms.docs.documentsOne') : t('hrms.docs.documents', { count: data.pagination.total })) : '—'}
                    </p>
                </div>

                <div className="flex gap-2">
                    {canManage && pendingCount > 0 && (
                        <Button variant="secondary" loading={verifyingAll} onClick={verifyAll}>
                            {t('hrms.docs.verifyAll', { count: pendingCount })}
                        </Button>
                    )}
                    {canManage && <Button onClick={() => setUploading(true)}>{t('hrms.docs.uploadDocument')}</Button>}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {expiring !== null && expiring.length > 0 && (
                <Card title={t('hrms.docs.expiringTitle')} dense>
                    <ul className="divide-y divide-gray-100">
                        {expiring.slice(0, 5).map((document) => (
                            <li key={document.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium text-gray-900">{document.title}</span>
                                    <span className="text-xs text-gray-400">
                                        {document.employee?.name ?? '—'} · {t('hrms.docs.expires', { date: document.expires_at })}
                                    </span>
                                </span>
                                <Link
                                    to={document.employee?.id ? employeeUrl(document.employee.id, 'documents') : '/hrms/documents'}
                                    className="shrink-0 text-sm font-medium text-[var(--accent-soft-text)] hover:underline"
                                >
                                    {t('hrms.docs.review')}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Input label={t('hrms.docs.search')} placeholder={t('hrms.docs.searchPlaceholder')} value={filters.q} onChange={(e) => setFilter('q', e.target.value)} />
                <Select label={t('hrms.docs.type')} value={filters.document_type_id} onChange={(e) => setFilter('document_type_id', e.target.value)}>
                    <option value="">{t('hrms.docs.anyType')}</option>
                    {types.map((type) => (
                        <option key={type.id} value={type.id}>
                            {type.name}
                        </option>
                    ))}
                </Select>
                <Select label={t('hrms.docs.status')} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                    <option value="">{t('hrms.docs.anyStatus')}</option>
                    {STATUSES.map((status) => (
                        <option key={status.value} value={status.value}>
                            {t(`hrms.docs.${status.key}`)}
                        </option>
                    ))}
                </Select>
                <div className="flex items-end">
                    <ListSavedFilters page="hrms-documents" value={filters} onApply={(saved) => { setPage(1); setFilters({ ...FILTER_DEFAULTS, ...saved }); }} />
                </div>
            </div>

            {!rows ? (
                <ContentSkeleton size="md" />
            ) : (
                <>
                    <DocumentList
                        documents={rows}
                        showEmployee
                        canVerify={canManage}
                        canDelete={canManage}
                        canReplace={canManage}
                        onChanged={() => load(page, filters)}
                        onVerify={verify}
                        onReject={reject}
                        onDelete={remove}
                    />
                    {data && (
                        <Pagination
                            page={data.pagination.current_page}
                            pages={data.pagination.last_page}
                            total={data.pagination.total}
                            onChange={setPage}
                        />
                    )}
                </>
            )}

            <Modal open={uploading} onClose={() => setUploading(false)} title={t('hrms.docs.uploadDocument')}>
                <DocumentUploader
                    employees={canPickEmployee ? employees : null}
                    types={types}
                    onUploaded={() => {
                        setUploading(false);
                        toast.success(t('hrms.docs.uploaded'));
                        load(page, filters);
                    }}
                />
            </Modal>
        </div>
    );
}
