import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm, usePrompt } from '../ui/ConfirmDialog';
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
    const { t } = useTranslation();
    const { user, can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const prompt = usePrompt();

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
            .catch(() => setError(t('hrms.docs.profileFailed')));
    }, [employee.id, t]);

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
            toast.success(t('hrms.docs.verified', { title: document.title }));
            await load();
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
            await load();
        } catch (err) {
            toast.error(err.response?.data?.errors?.reason ?? t('hrms.docs.rejectFailed'));
        }
    }

    async function remove(document) {
        if (!(await confirm({ title: t('hrms.docs.deleteTitle'), message: t('hrms.docs.deleteMessage', { title: document.title }), variant: 'danger' }))) return;

        try {
            await api.delete(`/hrms/documents/${document.id}`);
            toast.success(t('hrms.docs.deleted'));
            await load();
        } catch {
            toast.error(t('hrms.docs.deleteFailed'));
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
                            className="text-sm font-medium text-[var(--accent-soft-text)] hover:underline"
                        >
                            {uploading ? t('hrms.docs.hideUpload') : t('hrms.docs.showUpload')}
                        </button>
                        {uploading && (
                            <div className="pt-3">
                                <DocumentUploader
                                    employeeId={employee.id}
                                    types={types}
                                    onUploaded={() => {
                                        setUploading(false);
                                        toast.success(t('hrms.docs.uploaded'));
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
