import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Spinner from '../ui/Spinner';
import { useToast } from '../../context/ToastContext';
import Timestamp from '../ui/Timestamp';

const EMPTY = { name: '', abilities: [], can_write: false, rate_limit: '', expires_at: '' };

/** Personal API tokens: create (plaintext shown once), see usage, read the call log, revoke. */
export default function ApiTokens() {
    const { t } = useTranslation();
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
            toast.success(t('admin.apiTokens.revokedToast'));
            setConfirming(null);
            load();
        } catch (err) {
            toast.error(fieldErrors(err).form || t('admin.apiTokens.revokeFailed'));
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
                    {t('admin.apiTokens.intro')}
                </p>
                <Button size="sm" onClick={() => setCreating((v) => !v)}>{t('admin.apiTokens.newToken')}</Button>
            </div>

            {plaintext && (
                <Alert type="success">
                    <p className="font-semibold">{t('admin.apiTokens.copyNow')}</p>
                    <code className="mt-1 block break-all rounded bg-white/70 p-2 text-xs">{plaintext}</code>
                    <button className="mt-2 text-xs underline" onClick={() => setPlaintext(null)}>{t('admin.apiTokens.saved')}</button>
                </Alert>
            )}

            {creating && (
                <form onSubmit={create} className="space-y-3 rounded-lg border border-gray-200 p-4">
                    <Input label={t('admin.common.name')} name="name" required value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} />
                    <div>
                        <p className="mb-1.5 text-sm font-medium text-gray-700">{t('admin.apiTokens.abilities')}</p>
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
                        {t('admin.apiTokens.allowWrites')}
                    </label>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Input label={t('admin.apiTokens.rateLimit', { default: data.default_rate_limit })} name="rate_limit" type="number" value={form.rate_limit} onChange={(e) => setForm((f) => ({ ...f, rate_limit: e.target.value }))} error={errors.rate_limit} />
                        <Input label={t('admin.apiTokens.expiresOptional')} name="expires_at" type="date" value={form.expires_at} onChange={(e) => setForm((f) => ({ ...f, expires_at: e.target.value }))} error={errors.expires_at} />
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => setCreating(false)}>{t('admin.common.cancel')}</Button>
                        <Button type="submit" loading={saving}>{t('admin.apiTokens.createToken')}</Button>
                    </div>
                </form>
            )}

            {data.tokens.length === 0 ? <p className="py-6 text-center text-sm text-gray-400">{t('admin.apiTokens.noTokens')}</p> : (
                <ul className="space-y-3">
                    {data.tokens.map((token) => (
                        <li key={token.id} className="rounded-xl border border-gray-200 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-900">{token.name} <span className="font-mono text-xs text-gray-400">{token.hint}</span></p>
                                    <p className="text-xs text-gray-500">
                                        {token.abilities.join(', ')} · {token.can_write ? t('admin.apiTokens.readWrite') : t('admin.apiTokens.readOnly')}
                                        {token.expires_at ? <> · {t('admin.apiTokens.expires')} <Timestamp date={token.expires_at} /></> : ` · ${t('admin.apiTokens.noExpiry')}`}
                                    </p>
                                    <p className="text-xs text-gray-400">{token.last_used_at ? <>{t('admin.apiTokens.lastUsed')} <Timestamp date={token.last_used_at} /></> : t('admin.apiTokens.neverUsed')}</p>
                                </div>
                                <Badge>{token.active ? t('admin.apiTokens.active') : token.revoked_at ? t('admin.apiTokens.revoked') : t('admin.apiTokens.expired')}</Badge>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button size="sm" variant="secondary" onClick={() => toggleLogs(token.id)}>{logsFor === token.id ? t('admin.apiTokens.hideLog') : t('admin.apiTokens.callLog')}</Button>
                                {token.active && (confirming === token.id ? (
                                    <>
                                        <Button size="sm" variant="danger" onClick={() => revoke(token.id)}>{t('admin.apiTokens.confirmRevoke')}</Button>
                                        <Button size="sm" variant="secondary" onClick={() => setConfirming(null)}>{t('admin.apiTokens.keep')}</Button>
                                    </>
                                ) : (
                                    <Button size="sm" variant="danger" onClick={() => setConfirming(token.id)}>{t('admin.apiTokens.revoke')}</Button>
                                ))}
                            </div>
                            {logsFor === token.id && (
                                <div className="mt-3 overflow-x-auto rounded-lg border border-gray-100">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-gray-50 text-left uppercase text-gray-500"><tr><th scope="col" className="px-3 py-2">{t('admin.apiTokens.when')}</th><th scope="col" className="px-3 py-2">{t('admin.apiTokens.call')}</th><th scope="col" className="px-3 py-2">{t('admin.apiTokens.http')}</th><th scope="col" className="px-3 py-2">ms</th><th scope="col" className="px-3 py-2">IP</th></tr></thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {logs.length === 0 && <tr><td colSpan={5} className="px-3 py-4 text-center text-gray-400">{t('admin.apiTokens.noCalls')}</td></tr>}
                                            {logs.map((l) => (
                                                <tr key={l.id}>
                                                    <td className="px-3 py-2"><Timestamp date={l.created_at} /></td>
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
