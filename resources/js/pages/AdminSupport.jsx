import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import TicketThread, { PRIORITY_STYLES, STATUS_LABELS, StatusPill } from '../components/support/TicketThread';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import Timestamp from '../components/ui/Timestamp';
import { onRealtimeReconnect } from '../utils/realtimeAuth';

/** Super Admin support inbox: /admin/support (queue) and /admin/support/:ticketId (work a ticket). */
export default function AdminSupport() {
    const { ticketId } = useParams();
    return ticketId ? <Detail id={ticketId} /> : <Inbox />;
}

function Inbox() {
    const { t } = useTranslation();
    usePageTitle(t('admin.support.inboxTitle'));
    const setCrumbs = useSetCrumbs();
    const [filters, setFilters] = useState({ status: 'open_all', priority: '', assigned: '', q: '' });
    const [data, setData] = useState(null);

    useEffect(() => { setCrumbs([{ label: t('admin.support.inboxTitle') }]); }, [setCrumbs, t]);
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
                <h2 className="text-2xl font-bold text-gray-900">{t('admin.support.inboxTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('admin.support.inboxSubtitle')}</p>
            </div>
            {data && (
                <div className="flex flex-wrap gap-2 text-sm">
                    {Object.keys(STATUS_LABELS).map((k) => (
                        <button key={k} onClick={() => set({ status: k })} className="rounded-full bg-gray-100 px-3 py-1 text-gray-700 hover:bg-gray-200">{t(`admin.support.statuses.${k}`)}: <b>{data.counts[k] || 0}</b></button>
                    ))}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <input type="search" placeholder={t('admin.support.searchPlaceholder')} value={filters.q} onChange={(e) => set({ q: e.target.value })} className={`${sel} min-w-56 flex-1`} />
                <select className={sel} value={filters.status} onChange={(e) => set({ status: e.target.value })}>
                    <option value="open_all">{t('admin.support.allOpen')}</option>
                    {Object.keys(STATUS_LABELS).map((k) => <option key={k} value={k}>{t(`admin.support.statuses.${k}`)}</option>)}
                    <option value="">{t('admin.support.anyStatus')}</option>
                </select>
                <select className={sel} value={filters.priority} onChange={(e) => set({ priority: e.target.value })}>
                    <option value="">{t('admin.support.anyPriority')}</option>
                    {['urgent', 'high', 'normal', 'low'].map((p) => <option key={p} value={p}>{t(`admin.support.priorities.${p}`)}</option>)}
                </select>
                <select className={sel} value={filters.assigned} onChange={(e) => set({ assigned: e.target.value })}>
                    <option value="">{t('admin.support.anyone')}</option><option value="me">{t('admin.support.assignedToMe')}</option><option value="none">{t('admin.support.unassigned')}</option>
                </select>
            </div>
            {!data ? <div className="flex justify-center py-10"><Spinner /></div> : data.tickets.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-400">{t('admin.support.inboxZero')}</p>
            ) : (
                <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                    {data.tickets.map((ticket) => (
                        <li key={ticket.id}>
                            <Link to={`/admin/support/${ticket.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-gray-50">
                                <span className="font-mono text-xs text-gray-400">{ticket.reference}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium text-gray-800">{ticket.subject}</span>
                                    <span className="block truncate text-xs text-gray-500">{ticket.tenant?.name} · {ticket.created_by_name}</span>
                                </span>
                                <span className={`text-xs capitalize ${PRIORITY_STYLES[ticket.priority]}`}>{ticket.priority}</span>
                                <StatusPill status={ticket.status} />
                                <span className="w-24 truncate text-xs text-gray-500">{ticket.assignee || t('admin.support.unassigned')}</span>
                                <span className="text-xs text-gray-400"><Timestamp date={ticket.last_activity_at} /></span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Detail({ id }) {
    const { t } = useTranslation();
    const { user } = useAuth();
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [ticket, setTicket] = useState(null);
    const [errors, setErrors] = useState({});
    usePageTitle(ticket ? t('admin.support.titleWithRef', { ref: ticket.reference }) : t('admin.support.pageTitle'));

    useEffect(() => { setCrumbs([{ label: t('admin.support.inboxTitle'), to: '/admin/support' }, { label: ticket?.reference || t('admin.support.ticketFallback') }]); }, [setCrumbs, ticket?.reference, t]);
    useEffect(() => { api.get(`/system/support/tickets/${id}`).then(({ data }) => setTicket(data.ticket)).catch(() => setTicket(false)); }, [id]);

    useEffect(() => {
        if (!window.Echo || !id) return undefined;
        const channel = window.Echo.private(`support.ticket.${id}.staff`);
        channel.listen('.support.message.sent', (data) => {
            if (data?.message) {
                setTicket((prev) => {
                    if (!prev || typeof prev !== 'object') return prev;
                    if (prev.messages?.some((m) => m.id === data.message.id)) return prev;
                    return {
                        ...prev,
                        status: data.ticket_status ?? prev.status,
                        last_activity_at: data.last_activity_at ?? prev.last_activity_at,
                        messages: [...(prev.messages || []), data.message],
                    };
                });
            }
        });
        channel.listen('.support.ticket.updated', (data) => {
            if (data) {
                setTicket((prev) => {
                    if (!prev || typeof prev !== 'object') return prev;
                    return {
                        ...prev,
                        status: data.status ?? prev.status,
                        priority: data.priority ?? prev.priority,
                        assigned_to: data.assigned_to !== undefined ? data.assigned_to : prev.assigned_to,
                        assignee: data.assignee !== undefined ? data.assignee : prev.assignee,
                        last_activity_at: data.last_activity_at ?? prev.last_activity_at,
                    };
                });
            }
        });
        // Messages pushed while the socket was down are not replayed: reload the thread.
        const stopReconnect = onRealtimeReconnect(() => {
            api.get(`/system/support/tickets/${id}`).then(({ data }) => setTicket(data.ticket)).catch(() => {});
        });
        return () => {
            channel.stopListening('.support.message.sent');
            channel.stopListening('.support.ticket.updated');
            stopReconnect();
            window.Echo.leaveChannel(channel.name);
        };
    }, [id]);

    async function patch(body) {
        try {
            const { data } = await api.put(`/system/support/tickets/${id}`, body);
            setTicket((prev) => ({ ...prev, ...data.ticket }));
            toast.success(t('admin.support.updated'));
        } catch (e) { toast.error(fieldErrors(e).form || t('admin.support.updateFailed')); }
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
    if (ticket === false) return <div className="mx-auto max-w-md py-20"><Alert>{t('admin.support.notFound')}</Alert></div>;
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
                <div><span className="text-xs font-semibold uppercase text-gray-400">{t('admin.support.sideTenant')}</span>
                    <p><Link className="font-medium text-[var(--accent-soft-text)] hover:underline" to={`/tenants/${ticket.tenant?.id}`}>{ticket.tenant?.name}</Link></p></div>
                <div><span className="text-xs font-semibold uppercase text-gray-400">{t('admin.support.sideRequester')}</span>
                    <p className="font-medium text-gray-800">{ticket.created_by_name}</p><p className="text-gray-500">{ticket.created_by_email}</p></div>
                <div><span className="text-xs font-semibold uppercase text-gray-400">{t('admin.support.sideCategory')}</span><p className="capitalize">{ticket.category.replace('_', ' ')}</p></div>
                <label className="block"><span className="text-xs font-semibold uppercase text-gray-400">{t('admin.common.status')}</span>
                    <select className={`${sel} mt-1 w-full`} value={ticket.status} onChange={(e) => patch({ status: e.target.value })}>
                        {Object.keys(STATUS_LABELS).map((k) => <option key={k} value={k}>{t(`admin.support.statuses.${k}`)}</option>)}
                    </select></label>
                <label className="block"><span className="text-xs font-semibold uppercase text-gray-400">{t('admin.support.priority')}</span>
                    <select className={`${sel} mt-1 w-full`} value={ticket.priority} onChange={(e) => patch({ priority: e.target.value })}>
                        {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{t(`admin.support.priorities.${p}`)}</option>)}
                    </select></label>
                <div><span className="text-xs font-semibold uppercase text-gray-400">{t('admin.support.sideAssignee')}</span>
                    <p>{ticket.assignee || t('admin.support.unassigned')}</p>
                    {ticket.assigned_to !== user?.id && <button className="mt-1 text-[var(--accent-soft-text)] hover:underline" onClick={() => patch({ assigned_to: user.id })}>{t('admin.support.assignToMe')}</button>}
                    {ticket.assigned_to && <button className="ml-3 mt-1 text-gray-500 hover:underline" onClick={() => patch({ assigned_to: null })}>{t('admin.support.unassign')}</button>}</div>
                <button className="text-gray-500 hover:underline" onClick={() => navigate('/admin/support')}>{t('admin.support.backToInbox')}</button>
            </aside>
        </div>
    );
}
