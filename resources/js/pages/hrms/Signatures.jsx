import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import usePageTitle from '../../hooks/usePageTitle';
import { formatDateTime } from '../../utils/format';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const TONE = { pending: 'warning', signed: 'success', declined: 'danger', cancelled: 'neutral' };

/** Documents waiting for your signature, and (for HR) the requests you have sent. */
export default function Signatures() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.signatures.pageTitle'));
    const { can } = useAuth();
    const toast = useToast();
    const manage = can('hrms.documents.manage');
    const [rows, setRows] = useState(null);
    const [people, setPeople] = useState([]);
    const [docs, setDocs] = useState([]);
    const [req, setReq] = useState({ employee_id: '', document_id: '', message: '' });
    const [signing, setSigning] = useState(null);
    const [form, setForm] = useState({ typed_name: '', password: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/signatures').then(({ data }) => setRows(data.signatures)).catch(() => setError(t('hrms.signatures.loadFailed')));
    }, [t]);

    useEffect(() => {
        load();
        if (manage) api.get('/hrms/employees', { params: { per_page: 100 } }).then(({ data }) => setPeople(data.employees || [])).catch(() => {});
    }, [load, manage]);

    useEffect(() => {
        if (!req.employee_id) { setDocs([]); return; }
        api.get('/hrms/documents', { params: { employee_id: req.employee_id, per_page: 50 } }).then(({ data }) => setDocs(data.documents || [])).catch(() => setDocs([]));
    }, [req.employee_id]);

    async function request(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/hrms/signatures', { employee_id: Number(req.employee_id), document_id: Number(req.document_id), message: req.message || null });
            toast.success(t('hrms.signatures.requested'));
            setReq({ employee_id: '', document_id: '', message: '' });
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(fe);
            setError(fe.form || fe.employee_id || null);
        }
    }

    async function sign(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post(`/hrms/signatures/${signing.id}/sign`, form);
            toast.success(t('hrms.signatures.signedToast'));
            setSigning(null);
            setForm({ typed_name: '', password: '' });
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function act(s, action, body = {}) {
        try {
            await api.post(`/hrms/signatures/${s.id}/${action}`, body);
            load();
        } catch (err) {
            setError(fieldErrors(err).reason || fieldErrors(err).form || t('hrms.signatures.actionFailed'));
        }
    }

    if (!rows) return (error ? <Alert>{error}</Alert> : <ContentSkeleton size="lg" />);

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('hrms.signatures.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('hrms.signatures.subtitle')}</p>
            </div>
            {error && <Alert>{error}</Alert>}

            {manage && (
                <Card title={t('hrms.signatures.requestTitle')}>
                    <form onSubmit={request} className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label htmlFor="sg-emp" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.signatures.signer')}</label>
                            <select id="sg-emp" className={fieldClass} value={req.employee_id} onChange={(e) => setReq({ employee_id: e.target.value, document_id: '', message: req.message })}>
                                <option value="">—</option>
                                {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="sg-doc" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.signatures.document')}</label>
                            <select id="sg-doc" className={fieldClass} value={req.document_id} onChange={(e) => setReq({ ...req, document_id: e.target.value })}>
                                <option value="">—</option>
                                {docs.map((d) => <option key={d.id} value={d.id}>{d.title}</option>)}
                            </select>
                        </div>
                        <Input label={t('hrms.signatures.message')} value={req.message} onChange={(e) => setReq({ ...req, message: e.target.value })} />
                        <div className="sm:col-span-3"><Button type="submit" disabled={!req.employee_id || !req.document_id}>{t('hrms.signatures.send')}</Button></div>
                    </form>
                </Card>
            )}

            <Card>
                {rows.length === 0 ? <p className="py-8 text-center text-sm text-gray-500">{t('hrms.signatures.empty')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {rows.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <p className="font-medium text-gray-900">{s.document.title} <Badge tone={TONE[s.status]}>{t(`hrms.signatures.status.${s.status}`)}</Badge></p>
                                    <p className="text-gray-500">{s.signer.name}{s.signed_at ? ` · ${formatDateTime(s.signed_at)}` : ''}{s.message ? ` · ${s.message}` : ''}{s.decline_reason ? ` · ${s.decline_reason}` : ''}</p>
                                    {s.verification_code && <p className="text-xs text-gray-500">{t('hrms.signatures.code')}: <a className="font-mono text-[var(--accent)]" href={`/app/verify-signature/${s.verification_code}`}>{s.verification_code}</a></p>}
                                </div>
                                <div className="flex gap-2">
                                    {s.can_sign && <>
                                        <Button size="sm" onClick={() => setSigning(s)}>{t('hrms.signatures.sign')}</Button>
                                        <Button size="sm" variant="secondary" onClick={() => { const reason = window.prompt(t('hrms.signatures.declineReason')); if (reason) act(s, 'decline', { reason }); }}>{t('hrms.signatures.decline')}</Button>
                                    </>}
                                    {s.can_cancel && !s.can_sign && <Button size="sm" variant="ghost" onClick={() => act(s, 'cancel')}>{t('hrms.signatures.cancel')}</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={!!signing} onClose={() => setSigning(null)} title={t('hrms.signatures.sign')} subtitle={signing?.document.title}>
                <form onSubmit={sign}>
                    <div className="space-y-4 px-6 py-5">
                        <p className="text-sm text-gray-600">{t('hrms.signatures.signHint')}</p>
                        <Input label={t('hrms.signatures.typedName')} value={form.typed_name} onChange={(e) => setForm({ ...form, typed_name: e.target.value })} error={errors.typed_name} required />
                        <Input label={t('hrms.signatures.password')} type="password" autoComplete="current-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} error={errors.password} required />
                        {errors.form && <Alert>{errors.form}</Alert>}
                    </div>
                    <div className="flex justify-end gap-2 border-t border-gray-100 px-6 py-4">
                        <Button type="button" variant="secondary" onClick={() => setSigning(null)}>{t('tms.columns.cancel')}</Button>
                        <Button type="submit" disabled={!form.typed_name.trim() || !form.password}>{t('hrms.signatures.signNow')}</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
