import { useState } from 'react';
import api from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import Spinner from '../components/ui/Spinner';
import Pagination from '../components/ui/Pagination';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import { usePaginated } from '../hooks/useResource';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];

function LeadDetail({ lead, onClose, onChanged }) {
    const toast = useToast();
    const confirm = useConfirm();
    const [status, setStatus] = useState(lead.status);
    const [saving, setSaving] = useState(false);

    async function save() {
        setSaving(true);
        try {
            await api.put(`/system/leads/${lead.id}`, { status });
            toast.success('Lead updated.');
            onChanged();
            onClose();
        } catch {
            toast.error('Could not update the lead.');
        } finally {
            setSaving(false);
        }
    }

    async function remove() {
        if (!(await confirm({ title: 'Delete lead?', message: `Delete the request from ${lead.name}? This cannot be undone.`, variant: 'danger' }))) return;
        await api.delete(`/system/leads/${lead.id}`);
        toast.success('Lead deleted.');
        onChanged();
        onClose();
    }

    return (
        <Modal open title={lead.name} onClose={onClose}>
            <div className="space-y-3 px-6 pb-6 pt-4 text-sm">
                <p className="text-gray-500">{lead.email}{lead.company ? ` · ${lead.company}` : ''}{lead.phone ? ` · ${lead.phone}` : ''}</p>
                <p>
                    <Badge>{lead.source}</Badge>{' '}
                    <span className="text-xs text-gray-400">{new Date(lead.created_at).toLocaleString()}</span>
                </p>
                {lead.plan_interest && <p className="text-gray-600">Interested in: <span className="font-medium">{lead.plan_interest}</span></p>}
                <div className="rounded-lg bg-gray-50 p-3 whitespace-pre-wrap text-gray-700">{lead.message}</div>
                <div className="flex items-center gap-2">
                    <label className="text-sm font-medium text-gray-700">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        {STATUSES.map((s) => (
                            <option key={s} value={s}>{s}</option>
                        ))}
                    </select>
                    <Button onClick={save} disabled={saving || status === lead.status}>{saving ? 'Saving…' : 'Save'}</Button>
                </div>
                <div className="flex justify-end">
                    <button type="button" onClick={remove} className="text-xs font-medium text-red-500 hover:text-red-400">delete lead</button>
                </div>
            </div>
        </Modal>
    );
}

export default function Leads() {
    usePageTitle('Leads');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Leads' }]);
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const [detail, setDetail] = useState(null);
    const { data, loading, reload, page, setPage } = usePaginated(
        (p, signal) => api.get('/system/leads', { signal, params: { page: p, status: status || undefined, q: q || undefined, per_page: 15 } }).then(({ data }) => data),
        [status, q],
    );
    const leads = data?.leads || [];
    const pagination = data?.pagination;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-800">Leads</h1>
                <p className="text-sm text-gray-500">Contact and demo requests from the marketing site.</p>
            </div>

            <Card>
                <div className="mb-4 flex max-w-xl items-end gap-3">
                    <div className="flex-1">
                        <Input label="Search" name="q" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder="Name, email, company" />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
                        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="">All</option>
                            {STATUSES.map((s) => (
                                <option key={s} value={s}>{s}</option>
                            ))}
                        </select>
                    </div>
                </div>
                {loading ? (
                    <Spinner />
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th className="pb-2 font-semibold">Lead</th>
                                <th className="pb-2 font-semibold">Source</th>
                                <th className="pb-2 font-semibold">Status</th>
                                <th className="pb-2 font-semibold">Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            {leads.map((lead) => (
                                <tr key={lead.id} className="cursor-pointer border-t border-gray-100 hover:bg-gray-50" onClick={() => setDetail(lead)}>
                                    <td className="py-2.5">
                                        <p className="font-medium text-gray-800">{lead.name}</p>
                                        <p className="text-xs text-gray-400">{lead.email}{lead.company ? ` · ${lead.company}` : ''}</p>
                                    </td>
                                    <td className="py-2.5"><Badge>{lead.source}</Badge></td>
                                    <td className="py-2.5"><Badge>{lead.status}</Badge></td>
                                    <td className="py-2.5 text-gray-500">{new Date(lead.created_at).toLocaleDateString()}</td>
                                </tr>
                            ))}
                            {leads.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">No leads yet.</td></tr>
                            )}
                        </tbody>
                    </table>
                )}
                {pagination && (
                    <div className="mt-4">
                        <Pagination page={page} pages={pagination.last_page} total={pagination.total} onChange={setPage} />
                    </div>
                )}
            </Card>

            {detail && <LeadDetail lead={detail} onClose={() => setDetail(null)} onChanged={reload} />}
        </div>
    );
}
