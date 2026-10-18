import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Card from '../ui/Card';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';

/** Tenant admins: connect an OpenID Connect identity provider (Google, Microsoft Entra, Okta, Keycloak…). */
export default function SsoSettings() {
    const { t } = useTranslation();
    const toast = useToast();
    const [data, setData] = useState(null);
    const [roles, setRoles] = useState([]);
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/sso-settings').then(({ data: res }) => {
            setData(res);
            setForm({ ...res.sso, client_secret: '', allowed_domains: res.sso.allowed_domains.join(', ') });
        }).catch(() => setData(false));
        api.get('/roles').then(({ data: res }) => setRoles((res.roles || []).filter((r) => r.slug !== 'admin'))).catch(() => {});
    }, []);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const body = { ...form, allowed_domains: form.allowed_domains.split(',').map((d) => d.trim()).filter(Boolean) };
            const { data: res } = await api.put('/sso-settings', body);
            setForm({ ...res.sso, client_secret: '', allowed_domains: res.sso.allowed_domains.join(', ') });
            toast.success(t('admin.sso.saved'));
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    if (data === false) return null;
    if (!form) return <Card><ContentSkeleton size="sm" /></Card>;

    return (
        <Card title={t('admin.sso.title')} subtitle={t('admin.sso.subtitle')}>
            <form onSubmit={save} className="space-y-4">
                {errors.form && <Alert>{errors.form}</Alert>}
                <p className="text-sm text-gray-600">{t('admin.sso.redirectHint')} <code className="rounded bg-gray-100 px-1.5 py-0.5 text-xs">{data.redirect_uri}</code></p>
                <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={form.enabled} onChange={set('enabled')} /> {t('admin.sso.enabled')}</label>
                <Input label={t('admin.sso.issuer')} placeholder="https://accounts.example.com" value={form.issuer} onChange={set('issuer')} error={errors.issuer} />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label={t('admin.sso.clientId')} value={form.client_id} onChange={set('client_id')} error={errors.client_id} />
                    <Input label={t('admin.sso.clientSecret')} type="password" autoComplete="new-password" placeholder={data.sso.has_secret ? t('admin.sso.secretKept') : ''} value={form.client_secret} onChange={set('client_secret')} error={errors.client_secret} />
                </div>
                <Input label={t('admin.sso.domains')} placeholder="example.com, example.org" value={form.allowed_domains} onChange={set('allowed_domains')} error={errors.allowed_domains} />
                <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={form.jit} onChange={set('jit')} /> {t('admin.sso.jit')}</label>
                {form.jit && (
                    <div>
                        <label htmlFor="sso-role" className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.sso.defaultRole')}</label>
                        <select id="sso-role" className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm" value={form.default_role} onChange={set('default_role')}>
                            {roles.map((r) => <option key={r.slug} value={r.slug}>{r.name}</option>)}
                        </select>
                        {errors.default_role && <p className="mt-1 text-xs text-red-600">{errors.default_role}</p>}
                    </div>
                )}
                <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={form.enforce} onChange={set('enforce')} /> {t('admin.sso.enforce')}</label>
                <p className="text-xs text-gray-500">{t('admin.sso.enforceHint')}</p>
                <Button type="submit" loading={saving}>{t('admin.sso.save')}</Button>
            </form>
        </Card>
    );
}
