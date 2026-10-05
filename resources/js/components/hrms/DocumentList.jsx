import Button from '../ui/Button';
import EmptyState from '../ui/EmptyState';
import { Table, Th, Td } from '../ui/Table';

const STATUS_COLORS = {
    pending: '#f59e0b',
    verified: '#10b981',
    rejected: '#ef4444',
    expired: '#6b7280',
};

function StatusPill({ status }) {
    const color = STATUS_COLORS[status] ?? '#6b7280';

    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
            style={{ backgroundColor: `${color}22`, color }}
        >
            <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: color }} />
            {status}
        </span>
    );
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(`${value}T00:00:00`).toLocaleDateString();
}

/**
 * The document rows every document surface shares: the store, “My files”,
 * and the profile tab.
 *
 * One component because three copies of “which buttons does this row get” is
 * how verify ends up on a surface that should not have it. The caller passes
 * what its reader may do — the backend 403s regardless, these flags only
 * decide what gets drawn.
 */
export default function DocumentList({ documents, canVerify, canDelete, onVerify, onReject, onDelete, showEmployee }) {
    if (!documents || documents.length === 0) {
        // A real empty state, not a bare table row: this renders outside
        // any <table>, where a <tr> would be dropped by the browser and
        // read as a blank gap.
        return <EmptyState title="No documents" description="Nothing filed here yet." />;
    }

    return (
        <Table>
            <thead>
                <tr>
                    <Th>Document</Th>
                    {showEmployee && <Th>Employee</Th>}
                    <Th>Type</Th>
                    <Th>Status</Th>
                    <Th>Expires</Th>
                    <Th>File</Th>
                    {(canVerify || canDelete) && <Th><span className="sr-only">Actions</span></Th>}
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
                                        Confidential
                                    </span>
                                )}
                            </span>
                            <span className="block text-xs text-gray-400">{document.original_name}</span>
                        </Td>
                        {showEmployee && <Td>{document.employee?.name ?? '—'}</Td>}
                        <Td>{document.type?.name ?? '—'}</Td>
                        <Td><StatusPill status={document.status} /></Td>
                        <Td>{formatDate(document.expires_at)}</Td>
                        <Td>
                            <a
                                href={document.download_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm font-medium text-indigo-600 hover:underline"
                            >
                                Download
                            </a>
                        </Td>
                        {(canVerify || canDelete) && (
                            <Td>
                                <div className="flex justify-end gap-2">
                                    {canVerify && document.status === 'pending' && (
                                        <Button size="sm" variant="secondary" onClick={() => onVerify?.(document)}>
                                            Verify
                                        </Button>
                                    )}
                                    {canVerify && (document.status === 'pending' || document.status === 'verified') && (
                                        <Button size="sm" variant="ghost" onClick={() => onReject?.(document)}>
                                            Reject
                                        </Button>
                                    )}
                                    {canDelete && (
                                        <Button size="sm" variant="ghost" onClick={() => onDelete?.(document)}>
                                            Delete
                                        </Button>
                                    )}
                                </div>
                            </Td>
                        )}
                    </tr>
                ))}
            </tbody>
        </Table>
    );
}
