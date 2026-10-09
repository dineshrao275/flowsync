import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Pagination from '../../components/ui/Pagination';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { employeeUrl } from '../../utils/deepLinks';
import DocumentList from '../../components/hrms/DocumentList';
import DocumentUploader from '../../components/hrms/DocumentUploader';

const FILTER_DEFAULTS = { q: '', document_type_id: '', status: '' };

const STATUSES = [
    { value: 'pending', label: 'Pending' },
    { value: 'verified', label: 'Verified' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'expired', label: 'Expired' },
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
    usePageTitle('Documents');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

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
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Documents' }]);
    }, [setCrumbs]);

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

                    setError('Unable to load documents.');
                });
        },
        [navigate],
    );

    // eslint-disable-next-line react-hooks/exhaustive-deps
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
            api.get('/hrms/employees', { params: { per_page: 100 } })
                .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
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
            toast.success(`“${document.title}” verified.`);
            await load(page, filters);
        } catch {
            toast.error('Unable to verify this document.');
        }
    }

    async function reject(document) {
        const reason = window.prompt(`Why is “${document.title}” being rejected?`, '');

        if (reason === null) return;

        try {
            await api.post(`/hrms/documents/${document.id}/reject`, { reason });
            toast.success(`“${document.title}” rejected.`);
            await load(page, filters);
        } catch (err) {
            toast.error(err.response?.data?.errors?.reason ?? 'Unable to reject this document.');
        }
    }

    async function remove(document) {
        if (!window.confirm(`Delete “${document.title}”? The file is removed and the row is hidden.`)) return;

        try {
            await api.delete(`/hrms/documents/${document.id}`);
            toast.success('Document deleted.');
            await load(page, filters);
        } catch {
            toast.error('Unable to delete this document.');
        }
    }

    async function verifyAll() {
        const pending = (data?.documents ?? []).filter((document) => document.status === 'pending');

        if (pending.length === 0) return;
        if (!window.confirm(`Verify ${pending.length} pending document${pending.length === 1 ? '' : 's'}?`)) return;

        setVerifyingAll(true);

        try {
            for (const document of pending) {
                // Sequential, not parallel: each verify is a state transition
                // with its own audit row, and a burst of them is how two
                // reviewers approve the same pile twice without noticing.
                // eslint-disable-next-line no-await-in-loop
                await api.post(`/hrms/documents/${document.id}/verify`);
            }

            toast.success(`${pending.length} document${pending.length === 1 ? '' : 's'} verified.`);
            await load(page, filters);
        } catch {
            toast.error('Some documents could not be verified.');
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
                    <h2 className="text-xl font-semibold text-gray-900">Documents</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? `${data.pagination.total} document${data.pagination.total === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>

                <div className="flex gap-2">
                    {canManage && pendingCount > 0 && (
                        <Button variant="secondary" loading={verifyingAll} onClick={verifyAll}>
                            Verify all {pendingCount} pending
                        </Button>
                    )}
                    {canManage && <Button onClick={() => setUploading(true)}>Upload document</Button>}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {expiring !== null && expiring.length > 0 && (
                <Card title="Expiring within 30 days" dense>
                    <ul className="divide-y divide-gray-100">
                        {expiring.slice(0, 5).map((document) => (
                            <li key={document.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium text-gray-900">{document.title}</span>
                                    <span className="text-xs text-gray-400">
                                        {document.employee?.name ?? '—'} · expires {document.expires_at}
                                    </span>
                                </span>
                                <Link
                                    to={document.employee?.id ? employeeUrl(document.employee.id, 'documents') : '/hrms/documents'}
                                    className="shrink-0 text-sm font-medium text-indigo-600 hover:underline"
                                >
                                    Review
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="grid gap-3 rounded-xl border border-gray-200/70 bg-white p-3 sm:grid-cols-2 lg:grid-cols-4">
                <Input label="Search" placeholder="Title or file name" value={filters.q} onChange={(e) => setFilter('q', e.target.value)} />
                <Select label="Type" value={filters.document_type_id} onChange={(e) => setFilter('document_type_id', e.target.value)}>
                    <option value="">Any type</option>
                    {types.map((type) => (
                        <option key={type.id} value={type.id}>
                            {type.name}
                        </option>
                    ))}
                </Select>
                <Select label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                    <option value="">Any status</option>
                    {STATUSES.map((status) => (
                        <option key={status.value} value={status.value}>
                            {status.label}
                        </option>
                    ))}
                </Select>
            </div>

            {!rows ? (
                <div className="flex justify-center py-10">
                    <Spinner />
                </div>
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

            <Modal open={uploading} onClose={() => setUploading(false)} title="Upload document">
                <DocumentUploader
                    employees={canPickEmployee ? employees : null}
                    types={types}
                    onUploaded={() => {
                        setUploading(false);
                        toast.success('Document uploaded.');
                        load(page, filters);
                    }}
                />
            </Modal>
        </div>
    );
}
