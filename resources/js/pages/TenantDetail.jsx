import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

const SECTIONS = [
    {
        key: 'legal',
        title: 'Legal & registration',
        fields: ['legal_name', 'registration_number', 'tax_id', 'industry', 'company_size'],
    },
    {
        key: 'address',
        title: 'Address',
        fields: ['country', 'street', 'city', 'state', 'postal_code'],
    },
    {
        key: 'billing',
        title: 'Billing',
        fields: ['billing_email', 'billing_address', 'billing_currency'],
    },
    {
        key: 'contact',
        title: 'Contact',
        fields: ['contact_phone', 'website', 'timezone', 'locale'],
    },
    {
        key: 'branding',
        title: 'Branding',
        fields: ['brand_domain', 'brand_logo_url', 'brand_primary_color'],
    },
];

const FIELDS = {
    legal_name: 'Legal name',
    registration_number: 'Registration number',
    tax_id: 'Tax ID',
    industry: 'Industry',
    company_size: 'Company size',
    country: 'Country (2-letter code)',
    street: 'Street',
    city: 'City',
    state: 'State / region',
    postal_code: 'Postal code',
    billing_email: 'Billing email',
    billing_address: 'Billing address',
    billing_currency: 'Currency',
    contact_phone: 'Contact phone',
    website: 'Website',
    timezone: 'Timezone',
    locale: 'Locale',
    brand_domain: 'Brand domain',
    brand_logo_url: 'Logo URL',
    brand_primary_color: 'Primary color',
};

function StatCell({ label, value }) {
    return (
        <div className="rounded-lg bg-white p-3 text-center shadow-sm">
            <p className="text-xl font-bold text-gray-800">{value}</p>
            <p className="text-xs text-gray-400">{label}</p>
        </div>
    );
}

function Group({ section, form, setForm, errors }) {
    return (
        <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{section.title}</h3>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {section.fields.map((key) => (
                    <Input
                        key={key}
                        label={FIELDS[key]}
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
    const [basicForm, setBasicForm] = useState({ name: '', slug: '', description: '' });

    useEffect(() => {
        setCrumbs([{ label: 'Tenants', to: '/tenants' }, { label: tenant?.name || 'Tenant' }]);
    }, [setCrumbs, tenant?.name]);

    useEffect(() => {
        let active = true;
        api.get(`/tenants/${tenantId}/profile`)
            .then(({ data }) => {
                if (!active) return;
                const t = data.tenant;
                setTenant(t);
                setProfile(Object.fromEntries(allProfileFields.map((key) => [key, t[key] ?? ''])));
                setBasicForm({ name: t.name, slug: t.slug, description: t.description || '' });
            })
            .catch(() => active && setError('Unable to load tenant profile.'))
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
    }, [tenantId]);

    usePageTitle(tenant?.name ? `${tenant.name} · Tenant` : 'Tenant');

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
            toast.success('Tenant profile updated.');
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
            setBasicForm({ name: data.tenant.name, slug: data.tenant.slug, description: data.tenant.description || '' });
            toast.success('Tenant updated.');
        } catch (err) {
            setBasicErrors(fieldErrors(err));
        } finally {
            setSavingBasic(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
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
                <p className="mt-1 text-sm text-gray-500">{tenant?.description || 'No description'}</p>
            </div>

            {error && <Alert>{error}</Alert>}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="animate-fade-in-up lg:col-span-2">
                    <Card title="Company profile" subtitle="Expand the information stored about this tenant.">
                        <form onSubmit={saveProfile} className="space-y-6">
                            {SECTIONS.map((section) => (
                                <Group key={section.key} section={section} form={profile} setForm={setProfile} errors={errors} />
                            ))}
                            {errors.form && <Alert>{errors.form}</Alert>}
                            <div className="flex gap-2 pt-1">
                                <Button type="submit" loading={saving}>
                                    Save profile
                                </Button>
                            </div>
                        </form>
                    </Card>
                </div>

                <div className="animate-fade-in-up" style={{ animationDelay: '100ms' }}>
                    <Card title="Tenant details" subtitle="Update name, slug and description.">
                        <form onSubmit={saveBasic} className="space-y-4">
                            <Input
                                label="Name"
                                name="name"
                                value={basicForm.name}
                                onChange={(e) => setBasicForm((f) => ({ ...f, name: e.target.value }))}
                                error={basicErrors.name}
                                required
                            />
                            <Input
                                label="Slug"
                                name="slug"
                                value={basicForm.slug}
                                onChange={(e) => setBasicForm((f) => ({ ...f, slug: e.target.value }))}
                                error={basicErrors.slug}
                                required
                            />
                            <Input
                                label="Description"
                                name="description"
                                value={basicForm.description}
                                onChange={(e) => setBasicForm((f) => ({ ...f, description: e.target.value }))}
                                error={basicErrors.description}
                            />
                            {basicErrors.form && <Alert>{basicErrors.form}</Alert>}
                            <Button type="submit" loading={savingBasic}>
                                Save tenant
                            </Button>
                        </form>
                    </Card>

                    <div className="mt-6 space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">Status</span>
                            <p className="mt-0.5 font-medium text-gray-800">{tenant?.status || '—'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">Provisioning</span>
                            <p className="mt-0.5 font-medium text-gray-800">{tenant?.provisioning_status || '—'}</p>
                        </div>
                        <div>
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">Trial ends</span>
                            <p className="mt-0.5 font-medium text-gray-800">
                                {tenant?.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleDateString() : '—'}
                            </p>
                        </div>
                    </div>

                    <div className="mt-6">
                        <Card title="Usage" subtitle="Live counts from the tenant DB (cached 60s)">
                            {stats ? (
                                <div className="grid grid-cols-2 gap-3">
                                    <StatCell label="Users" value={stats.users} />
                                    <StatCell label="Workspaces" value={stats.workspaces} />
                                    <StatCell label="Projects" value={stats.projects} />
                                    <StatCell label="Tasks" value={stats.tasks} />
                                </div>
                            ) : (
                                <p className="py-2 text-sm text-gray-400">Unavailable.</p>
                            )}
                        </Card>
                    </div>
                </div>
            </div>
        </div>
    );
}