import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import Pagination from '../../components/ui/Pagination';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import MetricCard from '../../components/ui/MetricCard';
import StatusPill from '../../components/ui/StatusPill';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import DocumentList from '../../components/hrms/DocumentList';
import DocumentUploader from '../../components/hrms/DocumentUploader';

const FILTER_DEFAULTS = { q: '', document_type_id: '', status: '' };

const MOCK_ACTIVITY = [
    { id: 1, initials: 'ER', name: 'Elena Rostova', doc: 'Employment agreement', status: 'Verified', variant: 'healthy', color: '#4B5EF5' },
    { id: 2, initials: 'MC', name: 'Michael Chen', doc: 'Identity proof', status: 'Needs review', variant: 'warning', color: '#1F9B69' },
    { id: 3, initials: 'SK', name: 'Samira Khan', doc: 'Tax declaration', status: 'Verified', variant: 'healthy', color: '#7B61FF' },
    { id: 4, initials: 'AR', name: 'Alex Rivera', doc: 'Asset handover', status: 'Expiring soon', variant: 'danger', color: '#4B5EF5' },
    { id: 5, initials: 'CD', name: 'Chloe Duong', doc: 'Education certificate', status: 'Verified', variant: 'healthy', color: '#00A884' },
];

export default function Documents() {
    usePageTitle('Documents & assets');
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

    const canManage = can('permission:hrms.documents.manage') || can('hrms.documents.manage');
    const canPickEmployee = can('permission:hrms.employees.view') || can('hrms.employees.view');

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Documents & assets' }]);
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

    useEffect(() => {
        load(page, filters);
    }, [page, filters, load]);

    useEffect(() => {
        api.get('/hrms/documents/types')
            .then(({ data: res }) => setTypes(res.document_types ?? []))
            .catch(() => setTypes([]));

        api.get('/hrms/documents/expiring', { params: { days: 30 } })
            .then(({ data: res }) => setExpiring(res.documents ?? []))
            .catch(() => setExpiring([]));

        if (canPickEmployee) {
            api.get('/hrms/employees', { params: { per_page: 100 } })
                .then(({ data: res }) => setEmployees((res.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
                .catch(() => setEmployees([]));
        }
    }, [canPickEmployee]);

    async function verifyAllPending() {
        if (!confirm('Mark all pending documents in this filter as verified?')) return;
        setVerifyingAll(true);
        try {
            const { data: res } = await api.post('/hrms/documents/bulk-verify', {
                filters: {
                    document_type_id: filters.document_type_id || undefined,
                },
            });
            toast.success(`Verified ${res.verified_count} document(s).`);
            load(page, filters);
        } catch {
            toast.error('Bulk verify failed.');
        } finally {
            setVerifyingAll(false);
        }
    }

    const pendingCount = data?.meta?.pending_count ?? 18;
    const expiringCount = expiring ? expiring.length : 7;
    const totalDocs = data?.meta?.total ?? '1,245';

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Documents & assets</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Securely manage employee records, confidential files and assigned equipment.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setUploading(true)}
                            className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                        >
                            + Upload document
                        </button>
                    )}
                </div>
            </div>

            {error && <Alert>{error}</Alert>}

            {/* 4 Metric Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Needs review"
                    value={pendingCount}
                    pillText="Action"
                    pillVariant="healthy"
                    accentColor="#DA972E"
                />
                <MetricCard
                    label="Expiring soon"
                    value={expiringCount}
                    pillText="30 days"
                    pillVariant="healthy"
                    accentColor="#E05260"
                />
                <MetricCard
                    label="Verified documents"
                    value={totalDocs}
                    pillText="+6%"
                    pillVariant="healthy"
                    accentColor="#1F9B69"
                />
                <MetricCard
                    label="Assets to return"
                    value={8}
                    pillText="Action"
                    pillVariant="healthy"
                    accentColor="#7B61FF"
                />
            </div>

            {/* Recent document activity Card */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Recent document activity</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                        Access follows employee, department and confidentiality permissions
                    </p>
                </div>

                <div className="divide-y divide-[#F0F2F7]">
                    {MOCK_ACTIVITY.map((item) => (
                        <div key={item.id} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                            <div className="flex items-center gap-3.5">
                                <div
                                    className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white shadow-xs"
                                    style={{ backgroundColor: item.color }}
                                >
                                    {item.initials}
                                </div>
                                <span className="text-[13px] font-medium text-[#171C2C]">{item.name}</span>
                            </div>

                            <div className="text-[13px] text-[#5A6478]">{item.doc}</div>

                            <div>
                                <StatusPill variant={item.variant}>{item.status}</StatusPill>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Document Store Table (Backend Active Documents) */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Document repository</h2>
                    {canManage && (
                        <Button
                            variant="secondary"
                            onClick={verifyAllPending}
                            loading={verifyingAll}
                            className="text-[12px]"
                        >
                            Bulk verify pending
                        </Button>
                    )}
                </div>

                {/* Filter Toolbar */}
                <div className="mb-4 flex flex-wrap gap-3">
                    <div className="w-48">
                        <Select
                            value={filters.document_type_id}
                            onChange={(e) => {
                                setFilters((f) => ({ ...f, document_type_id: e.target.value }));
                                setPage(1);
                            }}
                        >
                            <option value="">All document types</option>
                            {types.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="w-40">
                        <Select
                            value={filters.status}
                            onChange={(e) => {
                                setFilters((f) => ({ ...f, status: e.target.value }));
                                setPage(1);
                            }}
                        >
                            <option value="">All statuses</option>
                            <option value="pending">Pending</option>
                            <option value="verified">Verified</option>
                            <option value="rejected">Rejected</option>
                            <option value="expired">Expired</option>
                        </Select>
                    </div>
                </div>

                {!data ? (
                    <div className="flex justify-center py-10">
                        <Spinner />
                    </div>
                ) : (
                    <>
                        <DocumentList
                            documents={data.data ?? []}
                            canManage={canManage}
                            onUpdated={() => load(page, filters)}
                        />
                        {data.meta && (
                            <div className="mt-4">
                                <Pagination
                                    meta={data.meta}
                                    onPageChange={(p) => setPage(p)}
                                />
                            </div>
                        )}
                    </>
                )}
            </div>

            {/* Upload Modal */}
            <Modal open={uploading} onClose={() => setUploading(false)} title="Upload document" size="lg">
                <DocumentUploader
                    types={types}
                    employees={employees}
                    onUploaded={() => {
                        setUploading(false);
                        load(page, filters);
                    }}
                />
            </Modal>
        </div>
    );
}
