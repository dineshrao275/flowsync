import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import Spinner from '../ui/Spinner';
import { Table, Th, Td } from '../ui/Table';
import { useToast } from '../../context/ToastContext';

/**
 * A document's version history, with a replacement upload for people who may
 * file documents. Older versions stay downloadable; only the newest is
 * "current". The server decides who may replace (the 403/422 surfaces here).
 */
export default function DocumentVersionsModal({ document, canReplace, onClose, onChanged }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [versions, setVersions] = useState(null);
    const [file, setFile] = useState(null);
    const [expiresAt, setExpiresAt] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        api.get(`/hrms/documents/${document.id}/versions`)
            .then(({ data }) => setVersions(data.versions ?? []))
            .catch(() => setError(t('hrms.docs.versionsFailed')));
    }, [document.id, t]);

    useEffect(() => {
        load();
    }, [load]);

    async function upload() {
        setBusy(true);
        setError(null);

        const body = new FormData();
        body.append('file', file);
        if (expiresAt) body.append('expires_at', expiresAt);

        try {
            const { data } = await api.post(`/hrms/documents/${document.id}/versions`, body, { headers: { 'Content-Type': 'multipart/form-data' } });
            toast.success(data.message);
            onChanged?.();
            onClose();
        } catch (err) {
            const errors = fieldErrors(err);
            setError(errors.file ?? errors.form ?? errors.expires_at ?? t('hrms.docs.uploadFailed'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal open onClose={onClose} title={t('hrms.docs.versionsTitle', { title: document.title })}>
            <div className="space-y-3 px-6 py-5">
                {error && <Alert>{error}</Alert>}
                {!versions ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : (
                    <Table>
                        <thead><tr><Th>{t('hrms.docs.thVersion')}</Th><Th>{t('hrms.docs.thFile')}</Th><Th>{t('hrms.docs.thStatus')}</Th><Th>{t('hrms.docs.thExpires')}</Th><Th>{t('hrms.docs.download')}</Th></tr></thead>
                        <tbody>
                            {[...versions].reverse().map((v) => (
                                <tr key={v.id}>
                                    <Td>v{v.version}{v.is_current && <span className="ml-1 rounded bg-emerald-100 px-1.5 text-xs text-emerald-700">{t('hrms.docs.current')}</span>}</Td>
                                    <Td>{v.original_name}</Td>
                                    <Td className="capitalize">{v.status}</Td>
                                    <Td>{v.expires_at ?? '—'}</Td>
                                    <Td><a className="text-[var(--accent-soft-text)] hover:underline" href={v.download_url} target="_blank" rel="noopener noreferrer">{t('hrms.docs.download')}</a></Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}

                {canReplace && document.is_current && (
                    <div className="space-y-2 rounded-lg border border-gray-200 p-3">
                        <p className="text-sm font-medium text-gray-700">{t('hrms.docs.newVersion')}</p>
                        <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                        <Input type="date" label={t('hrms.docs.newExpiry')} value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
                        <Button onClick={upload} disabled={!file || busy}>{t('hrms.docs.replace')}</Button>
                    </div>
                )}
            </div>
        </Modal>
    );
}
