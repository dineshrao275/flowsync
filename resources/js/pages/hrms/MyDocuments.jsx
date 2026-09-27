import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Modal from '../../components/ui/Modal';
import Pagination from '../../components/ui/Pagination';
import Spinner from '../../components/ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import DocumentList from '../../components/hrms/DocumentList';
import DocumentUploader from '../../components/hrms/DocumentUploader';

/**
 * The caller’s own files: what they filed, what HR said about it, and what
 * is about to expire.
 *
 * Self-scoped on purpose — this page needs no directory permission because it
 * only ever shows the caller’s own rows. The upload form takes the caller’s
 * employment record from the same response that lists the files, so there is
 * no second lookup and no way to file into someone else’s record.
 */
export default function MyDocuments() {
    usePageTitle('My files');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [types, setTypes] = useState([]);
    const [expiring, setExpiring] = useState(null);
    const [error, setError] = useState(null);
    const [uploading, setUploading] = useState(false);

    const canManage = can('permission:hrms.documents.manage');
    // The employment record the mine endpoint reports: the only id this page
    // may ever file under, and the key the expiring warnings are filtered by.
    const employeeId = data?.employee_id ?? null;

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My files' }]);
    }, [setCrumbs]);

    const load = useCallback(
        (targetPage) => {
            setError(null);

            return api
                .get('/hrms/my/documents', { params: { page: targetPage, per_page: 20 } })
                .then(({ data: response }) => setData(response))
                .catch((err) => {
                    if (err.response?.status === 403) {
                        navigate('/403', { replace: true });
                        return;
                    }

                    setError('Unable to load your files.');
                });
        },
        [navigate],
    );

    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => {
        load(page);
    }, [page, load]);

    useEffect(() => {
        api.get('/hrms/documents/types')
            .then(({ data }) => setTypes(data.document_types ?? []))
            .catch(() => setTypes([]));

        // Scoped to the caller by the backend, like the list itself: a reader
        // without the directory permission still gets their own warnings.
        // The mine response has not necessarily arrived yet, so the filter
        // reads the record id once both responses are in (see below).
        api.get('/hrms/documents/expiring', { params: { days: 30 } })
            .then(({ data }) => setExpiring(data.documents ?? []))
            .catch(() => setExpiring([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const rows = data?.documents ?? null;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">My files</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {data ? `${data.pagination.total} document${data.pagination.total === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>

                {employeeId && (
                    <Button onClick={() => setUploading(true)}>Upload a file</Button>
                )}
            </div>

            {error && <Alert>{error}</Alert>}

            {expiring !== null && employeeId && expiring.filter((d) => d.employee_id === employeeId).length > 0 && (
                <Card title="Expiring within 30 days" dense>
                    <ul className="divide-y divide-gray-100">
                        {expiring.filter((d) => d.employee_id === employeeId).map((document) => (
                            <li key={document.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="min-w-0">
                                    <span className="block truncate font-medium text-gray-900">{document.title}</span>
                                    <span className="text-xs text-gray-400">expires {document.expires_at}</span>
                                </span>
                                <a
                                    href={document.download_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="shrink-0 text-sm font-medium text-indigo-600 hover:underline"
                                >
                                    Download
                                </a>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {!rows ? (
                <div className="flex justify-center py-10">
                    <Spinner />
                </div>
            ) : (
                <>
                    <DocumentList documents={rows} canVerify={false} canDelete={canManage} />
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

            <Modal open={uploading} onClose={() => setUploading(false)} title="Upload a file">
                {employeeId && (
                    <DocumentUploader
                        employeeId={employeeId}
                        types={types}
                        onUploaded={() => {
                            setUploading(false);
                            toast.success('File uploaded. HR will review it.');
                            load(page);
                        }}
                    />
                )}
            </Modal>
        </div>
    );
}
