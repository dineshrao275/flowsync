import { useCallback, useEffect, useState } from 'react';
import api from '../../services/api';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import DocumentList from './DocumentList';
import DocumentUploader from './DocumentUploader';

/**
 * The documents tab on an employee profile: this person’s files, and an
 * upload form for the readers who may file into it.
 *
 * The uploader renders for a manager or for the person themselves — the same
 * rule as the backend’s `upload` policy, mirrored here so the form never
 * appears for a caller the endpoint would refuse. Anything subtler (a
 * directory reader filing into someone else’s record) stays refused by the
 * 403, which is the real gate; this only decides what gets drawn.
 */
export default function EmployeeDocuments({ employee }) {
    const { user, can } = useAuth();
    const toast = useToast();

    const [documents, setDocuments] = useState(null);
    const [types, setTypes] = useState([]);
    const [error, setError] = useState(null);
    const [uploading, setUploading] = useState(false);

    const canManage = can('permission:hrms.documents.manage');
    const isSelf = employee.user?.id != null && employee.user.id === user?.id;
    const canUpload = canManage || isSelf;

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/hrms/documents', { params: { employee_id: employee.id, per_page: 50 } })
            .then(({ data }) => setDocuments(data.documents ?? []))
            .catch(() => setError('Unable to load these documents.'));
    }, [employee.id]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!canUpload) return;

        api.get('/hrms/documents/types')
            .then(({ data }) => setTypes(data.document_types ?? []))
            .catch(() => setTypes([]));
    }, [canUpload]);

    async function verify(document) {
        try {
            await api.post(`/hrms/documents/${document.id}/verify`);
            toast.success(`“${document.title}” verified.`);
            await load();
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
            await load();
        } catch (err) {
            toast.error(err.response?.data?.errors?.reason ?? 'Unable to reject this document.');
        }
    }

    async function remove(document) {
        if (!window.confirm(`Delete “${document.title}”? The file is removed and the row is hidden.`)) return;

        try {
            await api.delete(`/hrms/documents/${document.id}`);
            toast.success('Document deleted.');
            await load();
        } catch {
            toast.error('Unable to delete this document.');
        }
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!documents) {
        return (
            <div className="flex justify-center py-10">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {canUpload && (
                <div className="rounded-xl border border-gray-200/70 bg-white p-4">
                    <button
                        type="button"
                        onClick={() => setUploading((v) => !v)}
                        className="text-sm font-medium text-indigo-600 hover:underline"
                    >
                        {uploading ? 'Hide upload form' : 'Upload a document'}
                    </button>
                    {uploading && (
                        <div className="pt-3">
                            <DocumentUploader
                                employeeId={employee.id}
                                types={types}
                                onUploaded={() => {
                                    setUploading(false);
                                    toast.success('Document uploaded.');
                                    load();
                                }}
                            />
                        </div>
                    )}
                </div>
            )}
            <DocumentList
                documents={documents}
                showEmployee={false}
                canVerify={canManage}
                canDelete={canManage}
                onVerify={verify}
                onReject={reject}
                onDelete={remove}
            />
        </div>
    );
}
