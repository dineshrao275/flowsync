import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { formatDateTime } from '../../utils/format';

const EMPTY = { name: '', abilities: [], can_write: false, rate_limit: '', expires_at: '' };

/** Personal API tokens: create (plaintext shown once), see usage, read the call log, revoke. */
export default function ApiTokens() {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [creating, setCreating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [plaintext, setPlaintext] = useState(null);
    const [logsFor, setLogsFor] = useState(null);
    const [logs, setLogs] = useState([]);
    const [confirming, setConfirming] = useState(null);

    const load = useCallback(
        () => api.get('/api-tokens').then(({ data: d }) => setData(d)).catch(() => setData({ tokens: [], abilities: [] })),
        [],
    );
    useEffect(() => { load(); }, [load]);

    function toggleAbility(slug) {
        setForm((f) => ({
            ...f,
            abilities: f.abilities.includes(slug) ? f.abilities.filter((a) => a !== slug) : [...f.abilities, slug],
        }));
    }

    async function create(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const payload = {
                ...form,
                rate_limit: form.rate_limit === '' ? null : Number(form.rate_limit),
                expires_at: form.expires_at || null,
            };
            const res = await api.post('/api-tokens', payload);
            setPlaintext(res.data.plaintext);
            setCreating(false);
            setForm(EMPTY);
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function revoke(id) {
        try {
            await api.delete(`/api-tokens/${id}`);
            toast.success('Token revoked.');
            setConfirming(null);
            load();
        } catch (err) {
            toast.error(fieldErrors(err).form || 'Could not revoke the token.');
        }
    }

    async function toggleLogs(id) {
        if (logsFor === id) {
            setLogsFor(null);
            return;
        }
        const { data: d } = await api.get(`/api-tokens/${id}/logs`);
        setLogs(d.logs);
        setLogsFor(id);
    }

    if (!data) return <div className="flex justify-center py-8"><Spinner /></div>;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-gray-500">
                    Call <code>/api/v1</code> with <code>Authorization: Bearer &lt;token&gt;</code>. A token can never do more than you can.
                </p>
                <Button size="sm" onClick={() => setCreating((v) => !v)}>New token</Button>
            </div>

            {plaintext && (
                <Alert type="success">
                    <p className="font-semibold">Copy your token now, it is shown only once.</p>
                    <code className="mt-1 block break-all rounded bg-white/70 p-2 text-xs">{plaintext}</code>
                    <button className="mt-2 text-xs underline" onClick={() => setPlaintext(null)}>I have saved it</button>
                </Alert>
            )}

            {creating && (
                <form onSubmit={create} className="space-y-3 rounded-lg border border-gray-200 p-4">
                    <Input label="Name" name="name" required value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} />
                    <div>
                        <p className="mb-1.5 text-sm font-medium text-gray-700">Abilities</p>
                        {data.abilities.map((a) => (
                            <label key={a.slug} className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={form.abilities.includes(a.slug)} onChange={() => toggleAbility(a.slug)} />
                                {a.label} <code className="text-xs text-gray-400">{a.slug}</code>
                            </label>
                        ))}
                        {errors.abilities && <p className="mt-1 text-sm text-red-600">{errors.abilities}</p>}
                    </div>
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={form.can_write} onChange={(e) => setForm((f) => ({ ...f, can_write: e.target.checked }))} />
                        Allow writes (create tasks). Off means read-only.
                    </label>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Input label={`Requests per minute (default ${data.default_rate_limit})`} name="rate_limit" type="number" value={form.rate_limit} onChange={(e) => setForm((f) => ({ ...f, rate_limit: e.target.value }))} error={errors.rate_limit} />
                        <Input label="Expires (optional)" name="expires_at" type="date" value={form.expires_at} onChange={(e) => setForm((f) => ({ ...f, expires_at: e.target.value }))} error={errors.expires_at} />
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
                        <Button type="submit" loading={saving}>Create token</Button>
                    </div>
                </form>
            )}

            {data.tokens.length === 0 ? <p className="py-6 text-center text-sm text-gray-400">No tokens yet.</p> : (
                <ul className="space-y-3">
                    {data.tokens.map((t) => (
                        <li key={t.id} className="rounded-xl border border-gray-200 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-900">{t.name} <span className="font-mono text-xs text-gray-400">{t.hint}</span></p>
                                    <p className="text-xs text-gray-500">
                                        {t.abilities.join(', ')} · {t.can_write ? 'read + write' : 'read-only'}
                                        {t.expires_at ? ` · expires ${formatDateTime(t.expires_at)}` : ' · no expiry'}
                                    </p>
                                    <p className="text-xs text-gray-400">{t.last_used_at ? `Last used ${formatDateTime(t.last_used_at)}` : 'Never used'}</p>
                                </div>
                                <Badge>{t.active ? 'active' : t.revoked_at ? 'revoked' : 'expired'}</Badge>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button size="sm" variant="secondary" onClick={() => toggleLogs(t.id)}>{logsFor === t.id ? 'Hide log' : 'Call log'}</Button>
                                {t.active && (confirming === t.id ? (
                                    <>
                                        <Button size="sm" variant="danger" onClick={() => revoke(t.id)}>Confirm revoke</Button>
                                        <Button size="sm" variant="secondary" onClick={() => setConfirming(null)}>Keep</Button>
                                    </>
                                ) : (
                                    <Button size="sm" variant="danger" onClick={() => setConfirming(t.id)}>Revoke</Button>
                                ))}
                            </div>
                            {logsFor === t.id && (
                                <div className="mt-3 overflow-x-auto rounded-lg border border-gray-100">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-gray-50 text-left uppercase text-gray-500"><tr><th className="px-3 py-2">When</th><th className="px-3 py-2">Call</th><th className="px-3 py-2">HTTP</th><th className="px-3 py-2">ms</th><th className="px-3 py-2">IP</th></tr></thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {logs.length === 0 && <tr><td colSpan={5} className="px-3 py-4 text-center text-gray-400">No calls yet.</td></tr>}
                                            {logs.map((l) => (
                                                <tr key={l.id}>
                                                    <td className="px-3 py-2">{formatDateTime(l.created_at)}</td>
                                                    <td className="px-3 py-2 font-mono">{l.method} /{l.path}</td>
                                                    <td className="px-3 py-2">{l.status}</td>
                                                    <td className="px-3 py-2">{l.duration_ms}</td>
                                                    <td className="px-3 py-2">{l.ip}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
