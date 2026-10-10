import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import TicketThread, { PRIORITY_STYLES, StatusPill } from '../components/support/TicketThread';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import { formatDateTime } from '../utils/format';

const CATEGORIES = [['technical', 'Technical problem'], ['billing', 'Billing'], ['access', 'Access & accounts'], ['feature_request', 'Feature request'], ['other', 'Other']];
const PRIORITIES = [['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent']];

/** Tenant side of support: /support (list + new ticket) and /support/:ticketId (thread). */
export default function Support() {
    const { ticketId } = useParams();
    return ticketId ? <TicketDetail id={ticketId} /> : <TicketList />;
}

function TicketList() {
    usePageTitle('Support');
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [tickets, setTickets] = useState(null);
    const [filter, setFilter] = useState('open');
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ subject: '', category: 'technical', priority: 'normal', body: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => { setCrumbs([{ label: 'Support' }]); }, [setCrumbs]);

    const load = useCallback(() => {
        api.get('/support/tickets', { params: { status: filter } }).then(({ data }) => setTickets(data.tickets)).catch(() => setTickets([]));
    }, [filter]);
    useEffect(() => { load(); }, [load]);

    async function create(e) {
        e.preventDefault();
        setSaving(true); setErrors({});
        try {
            const { data } = await api.post('/support/tickets', form);
            toast.success(data.message);
            navigate(`/support/${data.ticket.id}`);
        } catch (err) { setErrors(fieldErrors(err)); } finally { setSaving(false); }
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Support</h2>
                    <p className="mt-1 text-sm text-gray-500">Raise an issue with the FlowSync team and follow the conversation here.</p>
                </div>
                <Button onClick={() => setCreating((v) => !v)}>New ticket</Button>
            </div>

            {creating && (
                <Card title="New ticket" subtitle="Tell us what happened and what you expected.">
                    <form onSubmit={create} className="space-y-4">
                        <Alert>{errors.form}</Alert>
                        <Input label="Subject" name="subject" required value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} error={errors.subject} className="bg-white dark:bg-[#161B26] border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6]" />
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label className="block text-sm font-medium text-gray-700">Category
                                <select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} className="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    {CATEGORIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            </label>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Priority
                                <select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} className="mt-1.5 w-full rounded-lg bg-white dark:bg-[#161B26] border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6] px-3 py-2 text-sm">
                                    {PRIORITIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            </label>
                        </div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Details
                            <textarea rows={6} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} className="mt-1.5 w-full rounded-lg bg-white dark:bg-[#161B26] border border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6] placeholder-gray-400 dark:placeholder-gray-500" />
                            {errors.body && <span className="mt-1 block text-sm text-red-600">{errors.body}</span>}
                        </label>
                        <div className="flex gap-2">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
                            <Button type="submit" loading={saving}>Submit ticket</Button>
                        </div>
                    </form>
                </Card>
            )}

            <div className="flex gap-2 text-sm">
                {[['open', 'Open'], ['closed', 'Resolved & closed'], ['', 'All']].map(([v, l]) => (
                    <button key={l} onClick={() => setFilter(v)} className={`rounded-full px-3 py-1 ${filter === v ? 'bg-indigo-600 text-white' : 'border-gray-200 dark:border-[#2F3A4C] text-gray-900 dark:text-[#F3F4F6]'}`}>{l}</button>
                ))}
            </div>

            {tickets === null ? <div className="flex justify-center py-10"><Spinner /></div> : tickets.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-400">No tickets here.</p>
            ) : (
                <ul className="divide-y divide-gray-200 dark:divide-[#2F3A4C] rounded-xl border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#161B26]">
                    {tickets.map((t) => (
                        <li key={t.id} className="hover:bg-gray-50 dark:hover:bg-[#1C2433]">
                            <Link to={`/support/${t.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 dark:text-gray-300 hover:bg-gray-50">
                                <span className="font-mono text-xs text-gray-400 dark:text-gray-500">{t.reference}</span>
                                <span className="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200">{t.subject}</span>
                                <span className={`text-xs capitalize ${PRIORITY_STYLES[t.priority]}`}>{t.priority}</span>
                                <StatusPill status={t.status} />
                                <span className="text-xs text-gray-400 dark:text-gray-500">{formatDateTime(t.last_activity_at)}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function TicketDetail({ id }) {
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [ticket, setTicket] = useState(null);
    const [errors, setErrors] = useState({});
    usePageTitle(ticket ? `${ticket.reference} · Support` : 'Support');

    useEffect(() => { setCrumbs([{ label: 'Support', to: '/support' }, { label: ticket?.reference || 'Ticket' }]); }, [setCrumbs, ticket?.reference]);
    useEffect(() => { api.get(`/support/tickets/${id}`).then(({ data }) => setTicket(data.ticket)).catch(() => setTicket(false)); }, [id]);

    async function send(body) {
        setErrors({});
        try {
            const { data } = await api.post(`/support/tickets/${id}/messages`, { body });
            setTicket(data.ticket);
            return true;
        } catch (e) { setErrors(fieldErrors(e)); return false; }
    }

    async function close() {
        if (!window.confirm('Close this ticket? You can open a new one if the problem continues.')) return;
        const { data } = await api.post(`/support/tickets/${id}/close`);
        setTicket(data.ticket);
        toast.success(data.message);
    }

    if (ticket === null) return <div className="flex justify-center py-20"><Spinner /></div>;
    if (ticket === false) return <div className="mx-auto max-w-md py-20"><Alert>That ticket does not exist.</Alert></div>;

    return (
        <div className="mx-auto max-w-3xl space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-gray-400">{ticket.reference}</p>
                    <h2 className="text-2xl font-bold text-gray-900">{ticket.subject}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        <span className="capitalize">{ticket.category.replace('_', ' ')}</span> · <span className="capitalize">{ticket.priority}</span> priority · opened {formatDateTime(ticket.created_at)} by {ticket.created_by_name}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <StatusPill status={ticket.status} />
                    {ticket.status !== 'closed' && <Button size="sm" variant="secondary" onClick={close}>Close ticket</Button>}
                </div>
            </div>
            <TicketThread messages={ticket.messages} onSend={send} disabled={ticket.status === 'closed'} errors={errors} />
        </div>
    );
}
