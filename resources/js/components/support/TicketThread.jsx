import { useState } from 'react';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import { formatDateTime } from '../../utils/format';

export const STATUS_LABELS = {
    open: 'Open', in_progress: 'In progress', waiting_on_customer: 'Waiting on customer', resolved: 'Resolved', closed: 'Closed',
};
export const STATUS_STYLES = {
    open: 'bg-sky-100 text-sky-700', in_progress: 'bg-indigo-100 text-indigo-700', waiting_on_customer: 'bg-amber-100 text-amber-700',
    resolved: 'bg-emerald-100 text-emerald-700', closed: 'bg-gray-200 text-gray-600',
};
export const PRIORITY_STYLES = { low: 'text-gray-500', normal: 'text-gray-700', high: 'text-amber-700', urgent: 'text-red-700 font-semibold' };

export function StatusPill({ status }) {
    return <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLES[status] || 'bg-gray-100'}`}>{STATUS_LABELS[status] || status}</span>;
}

/** The conversation on a ticket plus the reply box; staff also get an "internal note" switch. */
export default function TicketThread({ messages, onSend, disabled = false, allowInternal = false, errors = {} }) {
    const [body, setBody] = useState('');
    const [internal, setInternal] = useState(false);
    const [sending, setSending] = useState(false);

    async function submit(e) {
        e.preventDefault();
        if (!body.trim()) return;
        setSending(true);
        try {
            const ok = await onSend(body, internal);
            if (ok !== false) { setBody(''); setInternal(false); }
        } finally { setSending(false); }
    }

    return (
        <div className="space-y-4">
            <ol className="space-y-3">
                {messages.map((m) => (
                    <li
                        key={m.id}
                        className={`rounded-xl border p-3 text-sm ${
                            m.is_internal ? 'border-amber-200 bg-amber-50' : m.author_type === 'staff' ? 'border-indigo-100 bg-indigo-50/50' : 'border-gray-200 bg-white'
                        }`}
                    >
                        <div className="mb-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span className="font-semibold text-gray-800">{m.author_name}</span>
                            <span>{m.author_type === 'staff' ? 'Support' : 'Customer'}</span>
                            {m.is_internal && <span className="rounded bg-amber-200 px-1.5 py-0.5 font-medium text-amber-900">Internal note</span>}
                            <span className="ml-auto">{formatDateTime(m.created_at)}</span>
                        </div>
                        <p className="whitespace-pre-wrap text-gray-800">{m.body}</p>
                    </li>
                ))}
            </ol>
            {disabled ? (
                <Alert type="info">This ticket is closed.</Alert>
            ) : (
                <form onSubmit={submit} className="space-y-2">
                    <textarea
                        value={body} onChange={(e) => setBody(e.target.value)} rows={4} placeholder={internal ? 'Internal note (the customer never sees this)…' : 'Write a reply…'}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                    />
                    {errors.body && <p className="text-sm text-red-600">{errors.body}</p>}
                    <Alert>{errors.form}</Alert>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" loading={sending} disabled={!body.trim()}>{internal ? 'Add note' : 'Send reply'}</Button>
                        {allowInternal && (
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} /> Internal note
                            </label>
                        )}
                    </div>
                </form>
            )}
        </div>
    );
}
