import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Trans, useTranslation } from 'react-i18next';
import LimitsEditor from '../components/billing/LimitsEditor';
import ProductsPanel from '../components/tenant/ProductsPanel';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import Timestamp from '../components/ui/Timestamp';

const SECTIONS = [
    { key: 'legal', fields: ['legal_name', 'registration_number', 'tax_id', 'industry', 'company_size'] },
    { key: 'address', fields: ['country', 'street', 'city', 'state', 'postal_code'] },
    { key: 'billing', fields: ['billing_email', 'billing_address', 'billing_currency'] },
    { key: 'contact', fields: ['contact_phone', 'website', 'timezone', 'locale'] },
    { key: 'branding', fields: ['brand_domain', 'brand_logo_url', 'brand_primary_color'] },
];

function StatCell({ label, value }) {
    return (
        <div className="rounded-lg bg-white p-3 text-center shadow-sm">
            <p className="text-xl font-bold text-gray-800">{value}</p>
            <p className="text-xs text-gray-400">{label}</p>
        </div>
    );
}

function Group({ section, form, setForm, errors }) {
    const { t } = useTranslation();
    return (
        <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{t(`admin.tenantDetail.sections.${section.key}`)}</h3>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {section.fields.map((key) => (
                    <Input
                        key={key}
                        label={t(`admin.tenantDetail.fields.${key}`, { defaultValue: key })}
                        name={key}
                        value={form[key] ?? ''}
                        onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
                        error={errors[key]}
                    />
                ))}
            </div>
        </section>
    );
}

const allProfileFields = SECTIONS.flatMap((s) => s.fields);

const emptyProfile = Object.fromEntries(allProfileFields.map((key) => [key, '']));

export default function TenantDetail() {
    const { tenantId } = useParams();
    const { t } = useTranslation();
    const toast = useToast();
    const setCrumbs = useSetCrumbs();
    const [tenant, setTenant] = useState(null);
    const [profile, setProfile] = useState(emptyProfile);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [savingBasic, setSavingBasic] = useState(false);
    const [errors, setErrors] = useState({});
    const [basicErrors, setBasicErrors] = useState({});
    const [basicForm, setBasicForm] = useState({ name: '', slug: '', description: '', limits_override: null });
    const [defaultUser, setDefaultUser] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: t('admin.tenants.pageTitle'), to: '/tenants' }, { label: tenant?.name || t('admin.tenantDetail.title') }]);
    }, [setCrumbs, t, tenant?.name]);

    useEffect(() => {
        let active = true;
        api.get(`/tenants/${tenantId}/profile`)
            .then(({ data }) => {
                if (!active) return;
                const t = data.tenant;
                setTenant(t);
                setProfile(Object.fromEntries(allProfileFields.map((key) => [key, t[key] ?? ''])));
                setBasicForm({ name: t.name, slug: t.slug, description: t.description || '', limits_override: t.limits_override || null });
            })
            .catch(() => active && setError(t('admin.tenantDetail.loadError')))
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
    }, [tenantId]);

    usePageTitle(tenant?.name ? `${tenant.name} · ${t('admin.tenantDetail.title')}` : t('admin.tenantDetail.title'));

    useEffect(() => {
        api.get(`/tenants/${tenantId}`)
            .then(({ data }) => {
                setDefaultUser(data.default_user);
            })
            .catch(() => {});
    }, [tenantId]);

    useEffect(() => {
        api.get(`/tenants/${tenantId}/stats`)
            .then(({ data }) => setStats(data.stats))
            .catch(() => {});
    }, [tenantId]);

    async function saveProfile(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const { data } = await api.put(`/tenants/${tenantId}/profile`, profile);
            setProfile(Object.fromEntries(allProfileFields.map((key) => [key, data.tenant[key] ?? ''])));
            setTenant((t) => ({ ...t, ...data.tenant }));
            toast.success(t('admin.tenantDetail.savedProfile'));
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    async function saveBasic(e) {
        e.preventDefault();
        setSavingBasic(true);
        setBasicErrors({});
        try {
            const { data } = await api.put(`/tenants/${tenantId}`, basicForm);
            setTenant((t) => ({ ...t, ...data.tenant }));
            setBasicForm({ name: data.tenant.name, slug: data.tenant.slug, description: data.tenant.description || '', limits_override: data.tenant.limits_override || null });
            toast.success(t('admin.tenantDetail.savedTenant'));
        } catch (err) {
            setBasicErrors(fieldErrors(err));
        } finally {
            setSavingBasic(false);
        }
    }

    if (loading) {
        return (
            <ContentSkeleton size="lg" />
        );
    }

    return (
        <div className="space-y-6">
            <div>
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-2xl font-bold text-gray-900">{tenant?.name}</h2>
                    <Badge>{tenant?.slug}</Badge>
                    {tenant?.status && <Badge>{tenant.status}</Badge>}
                </div>
                <p className="mt-1 text-sm text-gray-500">{tenant?.description || t('admin.tenantDetail.noDescription')}</p>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="animate-fade-in-up lg:col-span-2">
                    <Card title={t('admin.tenantDetail.companyProfile')} subtitle={t('admin.tenantDetail.companyProfileSubtitle')}>
                        <form onSubmit={saveProfile} className="space-y-6">
                            {SECTIONS.map((section) => (
                                <Group key={section.key} section={section} form={profile} setForm={setProfile} errors={errors} />
                            ))}
                            {errors.form && <Alert>{errors.form}</Alert>}
                            <div className="flex gap-2 pt-1">
                                <Button type="submit" loading={saving}>
                                    {t('admin.tenantDetail.saveProfile')}
                                </Button>
                            </div>
                        </form>
                    </Card>
                </div>

                <div className="animate-fade-in-up" style={{ animationDelay: '100ms' }}>
                    <Card title={t('admin.tenantDetail.tenantDetails')} subtitle={t('admin.tenantDetail.tenantDetailsSubtitle')}>
                        <form onSubmit={saveBasic} className="space-y-4">
                            <Input
                                label={t('admin.tenantDetail.name')}
                                name="name"
                                value={basicForm.name}
                                onChange={(e) => setBasicForm((f) => ({ ...f, name: e.target.value }))}
                                error={basicErrors.name}
                                required
                            />
                            <Input
                                label={t('admin.tenantDetail.slug')}
                                name="slug"
                                value={basicForm.slug}
                                onChange={(e) => setBasicForm((f) => ({ ...f, slug: e.target.value }))}
                                error={basicErrors.slug}
                                required
                            />
                            <Input
                                label={t('admin.tenantDetail.description')}
                                name="description"
                                value={basicForm.description}
                                onChange={(e) => setBasicForm((f) => ({ ...f, description: e.target.value }))}
                                error={basicErrors.description}
                            />
                            <LimitsEditor
                                limits={basicForm.limits_override}
                                errors={basicErrors}
                                legend={t('admin.tenantDetail.limitsLegend')}
                                hint={t('admin.tenantDetail.limitsHint')}
                                onChange={(next) => setBasicForm((f) => ({ ...f, limits_override: next }))}
                            />
                            {basicErrors.form && <Alert>{basicErrors.form}</Alert>}
                            <Button type="submit" loading={savingBasic}>
                                {t('admin.tenantDetail.saveTenant')}
                            </Button>
                        </form>
                    </Card>

                    <div className="mt-6 space-y-6">
                        {tenant?.status === 'draft' && (
                            <Alert>
                                <Trans
                                    i18nKey="admin.tenantDetail.draftNotice"
                                    components={[<Link key="0" className="font-semibold underline" to={`/tenants/${tenant.id}/setup`} />]}
                                />
                            </Alert>
                        )}
                        <Card title={t('admin.tenantDetail.defaultUser')} subtitle={t('admin.tenantDetail.defaultUserSubtitle')}>
                            {defaultUser ? (
                                <div className="text-sm">
                                    <p className="font-medium text-gray-800">{defaultUser.name}</p>
                                    <p className="text-gray-500">{defaultUser.email}</p>
                                </div>
                            ) : (
                                <p className="text-sm text-gray-400">{t('admin.tenantDetail.noDefaultUser')}</p>
                            )}
                        </Card>
                        <ProductsPanel tenantId={tenantId} onChanged={() => api.get(`/tenants/${tenantId}`).then(({ data }) => setDefaultUser(data.default_user)).catch(() => {})} />
                    </div>

                    <div className="mt-6 space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.tenantDetail.status')}</span>
                            <p className="mt-0.5 font-medium text-gray-800">{tenant?.status || '—'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.tenantDetail.provisioning')}</span>
                            <p className="mt-0.5 font-medium text-gray-800">{tenant?.provisioning_status || '—'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('admin.tenantDetail.trialEnds')}</span>
                            <p className="mt-0.5 font-medium text-gray-800">
                                <Timestamp date={tenant?.trial_ends_at} />
                            </p>
                        </div>
                    </div>

                    <div className="mt-6">
                        <Card title={t('admin.tenantDetail.usage')} subtitle={t('admin.tenantDetail.usageSubtitle')}>
                            {stats ? (
                                <div className="grid grid-cols-2 gap-3">
                                    <StatCell label={t('admin.tenantDetail.stats.users')} value={stats.users} />
                                    <StatCell label={t('admin.tenantDetail.stats.workspaces')} value={stats.workspaces} />
                                    <StatCell label={t('admin.tenantDetail.stats.projects')} value={stats.projects} />
                                    <StatCell label={t('admin.tenantDetail.stats.tasks')} value={stats.tasks} />
                                </div>
                            ) : (
                                <p className="py-2 text-sm text-gray-400">{t('admin.tenantDetail.unavailable')}</p>
                            )}
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    );
}