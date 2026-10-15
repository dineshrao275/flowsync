import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import TicketThread, { PRIORITY_STYLES, StatusPill } from '../components/support/TicketThread';
import RichTextEditor from '../components/ui/RichTextEditor';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import Timestamp from '../components/ui/Timestamp';

const CATEGORIES = ['technical', 'billing', 'access', 'feature_request', 'other'];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

/** Tenant side of support: /support (list + new ticket) and /support/:ticketId (thread). */
export default function Support() {
    const { ticketId } = useParams();
    return ticketId ? <TicketDetail id={ticketId} /> : <TicketList />;
}

function TicketList() {
    const { t } = useTranslation();
    usePageTitle(t('admin.support.pageTitle'));
    const navigate = useNavigate();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [tickets, setTickets] = useState(null);
    const [filter, setFilter] = useState('open');
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({ subject: '', category: 'technical', priority: 'normal', body: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => { setCrumbs([{ label: t('admin.support.pageTitle') }]); }, [setCrumbs, t]);

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
                    <h2 className="text-2xl font-bold text-gray-900">{t('admin.support.pageTitle')}</h2>
                    <p className="mt-1 text-sm text-gray-500">{t('admin.support.subtitle')}</p>
                </div>
                <Button onClick={() => setCreating((v) => !v)}>{t('admin.support.newTicket')}</Button>
            </div>

            {creating && (
                <Card title={t('admin.support.newTicket')} subtitle={t('admin.support.newTicketSubtitle')}>
                    <form onSubmit={create} className="space-y-4">
                        <Alert>{errors.form}</Alert>
                        <Input label={t('admin.support.subject')} name="subject" required value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} error={errors.subject} />
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label className="block text-sm font-medium text-gray-700">{t('admin.support.category')}
                                <select value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} className="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    {CATEGORIES.map((v) => <option key={v} value={v}>{t(`admin.support.categories.${v}`)}</option>)}
                                </select>
                            </label>
                            <label className="block text-sm font-medium text-gray-700">{t('admin.support.priority')}
                                <select value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} className="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    {PRIORITIES.map((v) => <option key={v} value={v}>{t(`admin.support.priorities.${v}`)}</option>)}
                                </select>
                            </label>
                        </div>
                        <div>
                            <span className="block text-sm font-medium text-gray-700">{t('admin.support.details')}</span>
                            <div className="mt-1.5">
                                <RichTextEditor
                                    value={form.body}
                                    onChange={(html) => setForm((f) => ({ ...f, body: html }))}
                                    placeholder={t('admin.support.bodyPlaceholder')}
                                    error={errors.body}
                                />
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>{t('admin.common.cancel')}</Button>
                            <Button type="submit" loading={saving}>{t('admin.support.submit')}</Button>
                        </div>
                    </form>
                </Card>
            )}

            <div className="flex gap-2 text-sm">
                {[['open', t('admin.support.filterOpen')], ['closed', t('admin.support.filterClosed')], ['', t('admin.support.filterAll')]].map(([v, l]) => (
                    <button key={l} onClick={() => setFilter(v)} className={`rounded-full px-3 py-1 ${filter === v ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'bg-gray-100 text-gray-600'}`}>{l}</button>
                ))}
            </div>

            {tickets === null ? <div className="flex justify-center py-10"><Spinner /></div> : tickets.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-400">{t('admin.support.noTickets')}</p>
            ) : (
                <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                    {tickets.map((ticket) => (
                        <li key={ticket.id}>
                            <Link to={`/support/${ticket.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-gray-50">
                                <span className="font-mono text-xs text-gray-400">{ticket.reference}</span>
                                <span className="min-w-0 flex-1 truncate font-medium text-gray-800">{ticket.subject}</span>
                                <span className={`text-xs capitalize ${PRIORITY_STYLES[ticket.priority]}`}>{ticket.priority}</span>
                                <StatusPill status={ticket.status} />
                                <span className="text-xs text-gray-400"><Timestamp date={ticket.last_activity_at} /></span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function TicketDetail({ id }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const setCrumbs = useSetCrumbs();
    const [ticket, setTicket] = useState(null);
    const [errors, setErrors] = useState({});
    usePageTitle(ticket ? t('admin.support.titleWithRef', { ref: ticket.reference }) : t('admin.support.pageTitle'));

    useEffect(() => { setCrumbs([{ label: t('admin.support.pageTitle'), to: '/support' }, { label: ticket?.reference || t('admin.support.ticketFallback') }]); }, [setCrumbs, ticket?.reference, t]);
    useEffect(() => { api.get(`/support/tickets/${id}`).then(({ data }) => setTicket(data.ticket)).catch(() => setTicket(false)); }, [id]);

    useEffect(() => {
        if (!window.Echo || !id) return undefined;
        const channel = window.Echo.private(`support.ticket.${id}`);
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
                        last_activity_at: data.last_activity_at ?? prev.last_activity_at,
                    };
                });
            }
        });
        return () => {
            channel.stopListening('.support.message.sent');
            channel.stopListening('.support.ticket.updated');
            window.Echo.leaveChannel(channel.name);
        };
    }, [id]);

    async function send(body) {
        setErrors({});
        try {
            const { data } = await api.post(`/support/tickets/${id}/messages`, { body });
            setTicket(data.ticket);
            return true;
        } catch (e) { setErrors(fieldErrors(e)); return false; }
    }

    async function close() {
        if (!(await confirm({ title: t('admin.support.closeTitle'), message: t('admin.support.closeMessage') }))) return;
        const { data } = await api.post(`/support/tickets/${id}/close`);
        setTicket(data.ticket);
        toast.success(data.message);
    }

    if (ticket === null) return <div className="flex justify-center py-20"><Spinner /></div>;
    if (ticket === false) return <div className="mx-auto max-w-md py-20"><Alert>{t('admin.support.notFound')}</Alert></div>;

    return (
        <div className="mx-auto max-w-3xl space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-gray-400">{ticket.reference}</p>
                    <h2 className="text-2xl font-bold text-gray-900">{ticket.subject}</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        <span className="capitalize">{ticket.category.replace('_', ' ')}</span> · <span className="capitalize">{ticket.priority}</span> {t('admin.support.priorityWord')} · {t('admin.support.opened')} <Timestamp date={ticket.created_at} /> {t('admin.support.by')} {ticket.created_by_name}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <StatusPill status={ticket.status} />
                    {ticket.status !== 'closed' && <Button size="sm" variant="secondary" onClick={close}>{t('admin.support.closeTicket')}</Button>}
                </div>
            </div>
            <TicketThread messages={ticket.messages} onSend={send} disabled={ticket.status === 'closed'} errors={errors} />
        </div>
    );
}
