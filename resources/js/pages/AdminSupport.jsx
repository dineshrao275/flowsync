import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import TicketThread, { PRIORITY_STYLES, STATUS_LABELS, StatusPill } from '../components/support/TicketThread';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import { formatDateTime } from '../utils/format';

/** Super Admin support inbox: /admin/support (queue) and /admin/support/:ticketId (work a ticket). */
export default function AdminSupport() {
    const { ticketId } = useParams();
    return ticketId ? <Detail id={ticketId} /> : <Inbox />;
}

function Inbox() {
    usePageTitle('Support inbox');
    const setCrumbs = useSetCrumbs();
    const [filters, setFilters] = useState({ status: 'open_all', priority: '', assigned: '', q: '' });
    const [data, setData] = useState(null);

    useEffect(() => { setCrumbs([{ label: 'Support inbox' }]); }, [setCrumbs]);
    const load = useCallback(() => {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v));
        api.get('/system/support/tickets', { params }).then(({ data: d }) => setData(d)).catch(() => setData({ tickets: [], counts: {} }));
    }, [filters]);
    useEffect(() => { load(); }, [load]);

    const set = (patch) => setFilters((f) => ({ ...f, ...patch }));
    const sel = 'rounded-lg border border-gray-300 px-3 py-2 text-sm';

    return (
        <div className="space-y-5">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">Support inbox</h2>
                <p className="mt-1 text-sm text-gray-500">Urgent first, then whatever has waited longest on us.</p>
            </div>
            {data && (
                <div className="flex flex-wrap gap-2 text-sm">
                    {Object.entries(STATUS_LABELS).map(([k, l]) => (
                        <button key={k} onClick={() => set({ status: k })} className="rounded-full bg-gray-100 px-3 py-1 text-gray-700 hover:bg-gray-200">{l}: <b>{data.counts[k] || 0}</b></button>
                    ))}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <input type="search" placeholder="Search subject or requester…" value={filters.q} onChange={(e) => set({ q: e.target.value })} className={`${sel} min-w-56 flex-1`} />
                <select className={sel} value={filters.status} onChange={(e) => set({ status: e.target.value })}>
                    <option value="open_all">All open</option>
                    {Object.entries(STATUS_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                    <option value="">Any status</option>
                </select>
                <select className={sel} value={filters.priority} onChange={(e) => set({ priority: e.target.value })}>
                    <option value="">Any priority</option>
                    {['urgent', 'high', 'normal', 'low'].map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <select className={sel} value={filters.assigned} onChange={(e) => set({ assigned: e.target.value })}>
                    <option value="">Anyone</option><option value="me">Assigned to me</option><option value="none">Unassigned</option>
                </select>
            </div>
            {!data ? <div className="flex justify-center py-10"><Spinner /></div> : data.tickets.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-400">Inbox zero.</p>
            ) : (
                <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                    {data.tickets.map((t) => (
                        <li key={t.id}>
                            <Link to={`/admin/support/${t.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-gray-50">
                                <span className="font-mono text-xs text-gray-400">{t.reference}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium text-gray-800">{t.subject}</span>
                                    <span className="block truncate text-xs text-gray-500">{t.tenant?.name} · {t.created_by_name}</span>
                                </span>
                                <span className={`text-xs capitalize ${PRIORITY_STYLES[t.priority]}`}>{t.priority}</span>
                                <StatusPill status={t.status} />
                                <span className="w-24 truncate text-xs text-gray-500">{t.assignee || 'Unassigned'}</span>
                                <span className="text-xs text-gray-400">{formatDateTime(t.last_activity_at)}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Detail({ id }) {
    const { user } = useAuth();
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [ticket, setTicket] = useState(null);
    const [errors, setErrors] = useState({});
    usePageTitle(ticket ? `${ticket.reference} · Support` : 'Support');

    useEffect(() => { setCrumbs([{ label: 'Support inbox', to: '/admin/support' }, { label: ticket?.reference || 'Ticket' }]); }, [setCrumbs, ticket?.reference]);
    useEffect(() => { api.get(`/system/support/tickets/${id}`).then(({ data }) => setTicket(data.ticket)).catch(() => setTicket(false)); }, [id]);

    async function patch(body) {
        try {
            const { data } = await api.put(`/system/support/tickets/${id}`, body);
            setTicket((t) => ({ ...t, ...data.ticket }));
            toast.success('Ticket updated.');
        } catch (e) { toast.error(fieldErrors(e).form || 'Could not update the ticket.'); }
    }

    async function send(body, internal) {
        setErrors({});
        try {
            const { data } = await api.post(`/system/support/tickets/${id}/messages`, { body, internal });
            setTicket(data.ticket);
            return true;
        } catch (e) { setErrors(fieldErrors(e)); return false; }
    }

    if (ticket === null) return <div className="flex justify-center py-20"><Spinner /></div>;
    if (ticket === false) return <div className="mx-auto max-w-md py-20"><Alert>That ticket does not exist.</Alert></div>;
    const sel = 'rounded-lg border border-gray-300 px-2 py-1.5 text-sm';

    return (
        <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-3">
            <div className="space-y-4 lg:col-span-2">
                <div>
                    <p className="font-mono text-xs text-gray-400">{ticket.reference}</p>
                    <h2 className="text-2xl font-bold text-gray-900">{ticket.subject}</h2>
                </div>
                <TicketThread messages={ticket.messages} onSend={send} allowInternal errors={errors} disabled={ticket.status === 'closed'} />
            </div>
            <aside className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                <div><span className="text-xs font-semibold uppercase text-gray-400">Tenant</span>
                    <p><Link className="font-medium text-indigo-600 hover:underline" to={`/tenants/${ticket.tenant?.id}`}>{ticket.tenant?.name}</Link></p></div>
                <div><span className="text-xs font-semibold uppercase text-gray-400">Requester</span>
                    <p className="font-medium text-gray-800">{ticket.created_by_name}</p><p className="text-gray-500">{ticket.created_by_email}</p></div>
                <div><span className="text-xs font-semibold uppercase text-gray-400">Category</span><p className="capitalize">{ticket.category.replace('_', ' ')}</p></div>
                <label className="block"><span className="text-xs font-semibold uppercase text-gray-400">Status</span>
                    <select className={`${sel} mt-1 w-full`} value={ticket.status} onChange={(e) => patch({ status: e.target.value })}>
                        {Object.entries(STATUS_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                    </select></label>
                <label className="block"><span className="text-xs font-semibold uppercase text-gray-400">Priority</span>
                    <select className={`${sel} mt-1 w-full`} value={ticket.priority} onChange={(e) => patch({ priority: e.target.value })}>
                        {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{p}</option>)}
                    </select></label>
                <div><span className="text-xs font-semibold uppercase text-gray-400">Assignee</span>
                    <p>{ticket.assignee || 'Unassigned'}</p>
                    {ticket.assigned_to !== user?.id && <button className="mt-1 text-indigo-600 hover:underline" onClick={() => patch({ assigned_to: user.id })}>Assign to me</button>}
                    {ticket.assigned_to && <button className="ml-3 mt-1 text-gray-500 hover:underline" onClick={() => patch({ assigned_to: null })}>Unassign</button>}</div>
                <button className="text-gray-500 hover:underline" onClick={() => navigate('/admin/support')}>← Back to inbox</button>
            </aside>
        </div>
    );
}
