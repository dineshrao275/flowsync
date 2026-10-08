import { useState } from 'react';

/** Shortens a stored morph class (`App\Models\…\Employee`) to its basename. */
export function shortSubjectType(subjectType) {
    if (!subjectType) return '—';
    const parts = String(subjectType).split('\\');
    return parts[parts.length - 1];
}

function DiffValue({ value }) {
    if (value === null || value === undefined) return <span className="text-gray-400">—</span>;
    if (typeof value === 'object') return <pre className="whitespace-pre-wrap text-xs text-gray-700">{JSON.stringify(value, null, 2)}</pre>;
    return <span className="text-gray-700">{String(value)}</span>;
}

/**
 * One ledger row's before/after payload. Values arrive masked from the
 * writer — this renders them verbatim and never interprets them, because a
 * viewer that prettifies `***` into something clickable invites copy-paste
 * of a redaction as if it were data.
 */
export function AuditDiff({ data }) {
    const before = data?.before ?? null;
    const after = data?.after ?? null;
    const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])];

    if (keys.length === 0) {
        return <p className="text-xs text-gray-400">No field snapshot on this row.</p>;
    }

    return (
        <div className="grid grid-cols-3 gap-2 text-sm">
            <p className="text-xs font-medium text-gray-400">Field</p>
            <p className="text-xs font-medium text-gray-400">Before</p>
            <p className="text-xs font-medium text-gray-400">After</p>
            {keys.map((key) => (
                <div key={key} className="contents">
                    <p className="font-mono text-xs text-gray-500">{key}</p>
                    <div className="min-w-0 break-words">
                        <DiffValue value={before?.[key]} />
                    </div>
                    <div className="min-w-0 break-words">
                        <DiffValue value={after?.[key]} />
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * The shared trail renderer: timestamp, actor, dotted action, subject, and
 * an expandable before/after diff. Used by the viewer page and the profile
 * tab so the same ledger never renders two dialects.
 */
export default function AuditTrail({ rows }) {
    const [openId, setOpenId] = useState(null);

    if (!rows || rows.length === 0) {
        return <p className="py-8 text-center text-sm text-gray-400">No trail rows yet.</p>;
    }

    return (
        <ul className="divide-y divide-gray-100">
            {rows.map((row) => (
                <li key={row.id} className="py-3">
                    <button
                        type="button"
                        onClick={() => setOpenId(openId === row.id ? null : row.id)}
                        className="flex w-full flex-wrap items-baseline gap-x-3 text-left"
                    >
                        <span className="font-mono text-xs text-gray-500">{row.action}</span>
                        <span className="text-xs text-gray-400">
                            {shortSubjectType(row.subject_type)} #{row.subject_id}
                        </span>
                        <span className="ml-auto text-xs text-gray-400">
                            {row.actor?.name ?? 'System'}
                            {row.created_at ? ` · ${new Date(row.created_at).toLocaleString()}` : ''}
                        </span>
                    </button>
                    {openId === row.id && (
                        <div className="mt-2 rounded-lg bg-gray-50 p-3">
                            <AuditDiff data={row.data} />
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}
