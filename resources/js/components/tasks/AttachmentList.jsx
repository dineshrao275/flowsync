import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useAuth } from '../../context/AuthContext';

function formatSize(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    return `${(bytes / 1024).toFixed(0)} KB`;
}

export default function AttachmentList({ task, projectId, canUpload, canManage }) {
    const { user } = useAuth();
    const [attachments, setAttachments] = useState(null);
    const [error, setError] = useState(null);
    const [file, setFile] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [uploadErrors, setUploadErrors] = useState({});

    function fetchAttachments() {
        api.get(`/projects/${projectId}/tasks/${task.id}/attachments`)
            .then(({ data }) => {
                setAttachments(data.attachments);
                setError(null);
            })
            .catch(() => setError('Could not load attachments.'));
    }

    useEffect(() => {
        fetchAttachments();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, task.id]);

    function upload(e) {
        e.preventDefault();
        if (!file) return;
        setUploading(true);
        setUploadErrors({});
        const formData = new FormData();
        formData.append('file', file);
        api.post(`/projects/${projectId}/tasks/${task.id}/attachments`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
            .then(() => {
                setFile(null);
                fetchAttachments();
            })
            .catch((err) => setUploadErrors(fieldErrors(err)))
            .finally(() => setUploading(false));
    }

    function remove(attachment) {
        if (!window.confirm(`Delete ${attachment.original_name}?`)) return;
        api.delete(`/projects/${projectId}/tasks/${task.id}/attachments/${attachment.id}`)
            .then(fetchAttachments)
            .catch(() => {});
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!attachments) {
        return (
            <div className="flex justify-center py-10">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {canUpload && (
                <form onSubmit={upload} className="flex gap-2">
                    <input
                        type="file"
                        className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    />
                    <Button type="submit" loading={uploading} disabled={!file}>
                        Upload
                    </Button>
                </form>
            )}
            {uploadErrors.file && <Alert>{uploadErrors.file}</Alert>}
            {uploadErrors.form && <Alert>{uploadErrors.form}</Alert>}

            {attachments.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-400">No attachments.</p>
            ) : (
                <ul className="divide-y divide-gray-100 rounded-lg border border-gray-100">
                    {attachments.map((attachment) => {
                        const own = attachment.user?.id && attachment.user.id === user?.id;
                        return (
                            <li key={attachment.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                                <div className="min-w-0">
                                    <a
                                        href={attachment.download_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="block truncate text-sm font-medium text-indigo-600 hover:underline"
                                    >
                                        {attachment.original_name}
                                    </a>
                                    <p className="text-xs text-gray-400">
                                        {attachment.user?.name ?? 'Unknown'} · {formatSize(attachment.size)} ·{' '}
                                        {new Date(attachment.created_at).toLocaleDateString()}
                                    </p>
                                </div>
                                {(canManage || own) && (
                                    <Button size="sm" variant="ghost" onClick={() => remove(attachment)}>
                                        Delete
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}