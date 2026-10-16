import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '../ui/Button';
import DocumentVersionsModal from './DocumentVersionsModal';
import EmptyState from '../ui/EmptyState';
import StatusPill from '../ui/StatusPill';
import { Table, Th, Td } from '../ui/Table';
import { formatDateOnly } from '../../utils/format';

const STATUS_COLORS = {
    pending: '#f59e0b',
    verified: '#10b981',
    rejected: '#ef4444',
    expired: '#6b7280',
};

const STATUS_KEYS = {
    pending: 'stPending',
    verified: 'stVerified',
    rejected: 'stRejected',
    expired: 'stExpired',
};

const formatDate = (value) => formatDateOnly(value);

/**
 * The document rows every document surface shares: the store, “My files”,
 * and the profile tab.
 *
 * One component because three copies of “which buttons does this row get” is
 * how verify ends up on a surface that should not have it. The caller passes
 * what its reader may do — the backend 403s regardless, these flags only
 * decide what gets drawn.
 */
export default function DocumentList({ documents, canVerify, canDelete, canReplace = false, onChanged, onVerify, onReject, onDelete, showEmployee }) {
    const { t } = useTranslation();
    const [versionsOf, setVersionsOf] = useState(null);

    if (!documents || documents.length === 0) {
        // A real empty state, not a bare table row: this renders outside
        // any <table>, where a <tr> would be dropped by the browser and
        // read as a blank gap.
        return <EmptyState title={t('hrms.docs.listEmpty')} description={t('hrms.docs.listEmptyHint')} />;
    }

    return (
        <>
        <Table>
            <thead>
                <tr>
                    <Th>{t('hrms.docs.thDocument')}</Th>
                    {showEmployee && <Th>{t('hrms.docs.thEmployee')}</Th>}
                    <Th>{t('hrms.docs.thType')}</Th>
                    <Th>{t('hrms.docs.thStatus')}</Th>
                    <Th>{t('hrms.docs.thExpires')}</Th>
                    <Th>{t('hrms.docs.thFile')}</Th>
                    {(canVerify || canDelete) && <Th><span className="sr-only">{t('hrms.docs.thActions')}</span></Th>}
                </tr>
            </thead>
            <tbody>
                {documents.map((document) => (
                    <tr key={document.id}>
                        <Td>
                            <span className="block font-medium text-gray-900">
                                {document.title}
                                {document.confidential && (
                                    <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">
                                        {t('hrms.docs.confidential')}
                                    </span>
                                )}
                            </span>
                            <span className="block text-xs text-gray-400">{document.original_name}</span>
                        </Td>
                        {showEmployee && <Td>{document.employee?.name ?? '—'}</Td>}
                        <Td>{document.type?.name ?? '—'}</Td>
                        <Td><StatusPill color={STATUS_COLORS[document.status]} label={STATUS_KEYS[document.status] ? t(`hrms.docs.${STATUS_KEYS[document.status]}`) : document.status} className="capitalize" /></Td>
                        <Td>{formatDate(document.expires_at)}</Td>
                        <Td>
                            <a
                                href={document.download_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm font-medium text-[var(--accent-soft-text)] hover:underline"
                            >
                                {t('hrms.docs.download')}
                            </a>
                            {(document.version > 1 || canReplace) && (
                                <button type="button" onClick={() => setVersionsOf(document)} className="ml-3 text-sm text-gray-500 hover:underline">
                                    v{document.version ?? 1}
                                </button>
                            )}
                        </Td>
                        {(canVerify || canDelete) && (
                            <Td>
                                <div className="flex justify-end gap-2">
                                    {canVerify && document.status === 'pending' && (
                                        <Button size="sm" variant="secondary" onClick={() => onVerify?.(document)}>
                                            {t('hrms.docs.verify')}
                                        </Button>
                                    )}
                                    {canVerify && (document.status === 'pending' || document.status === 'verified') && (
                                        <Button size="sm" variant="ghost" onClick={() => onReject?.(document)}>
                                            {t('hrms.docs.reject')}
                                        </Button>
                                    )}
                                    {canDelete && (
                                        <Button size="sm" variant="ghost" onClick={() => onDelete?.(document)}>
                                            {t('hrms.docs.delete')}
                                        </Button>
                                    )}
                                </div>
                            </Td>
                        )}
                    </tr>
                ))}
            </tbody>
        </Table>
            {versionsOf && (
                <DocumentVersionsModal document={versionsOf} canReplace={canReplace} onClose={() => setVersionsOf(null)} onChanged={onChanged} />
            )}
        </>
    );
}
