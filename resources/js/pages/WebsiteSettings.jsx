import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Spinner from '../components/ui/Spinner';
import MediaPicker from '../components/cms/MediaPicker';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useResource } from '../hooks/useResource';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function LinkRow({ link, onChange, onRemove }) {
    return (
        <div className="flex gap-2">
            <input
                value={link.label || ''}
                onChange={(e) => onChange({ ...link, label: e.target.value })}
                placeholder="Label"
                className="w-1/3 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
            />
            <input
                value={link.href || ''}
                onChange={(e) => onChange({ ...link, href: e.target.value })}
                placeholder="Link (/path or https://…)"
                className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
            />
            <button type="button" onClick={onRemove} className="px-1 text-red-400 hover:text-red-500">✕</button>
        </div>
    );
}

export default function WebsiteSettings() {
    usePageTitle('Site Settings');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Site settings' }]);
    const toast = useToast();
    const { data, loading, reload } = useResource((signal) =>
        api.get('/system/website-settings', { signal }).then(({ data }) => data.settings),
    );
    const [form, setForm] = useState(null);
    const [logo, setLogo] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (data) {
            setForm(data);
            setErrors({});
            if (data.logo_media_id) {
                api.get(`/system/media/${data.logo_media_id}`)
                    .then(({ data: media }) => setLogo(media.media))
                    .catch(() => setLogo(null));
            } else {
                setLogo(null);
            }
        }
    }, [data]);

    function set(field, value) {
        setForm((f) => ({ ...f, [field]: value }));
    }

    function pickLogo(media) {
        setLogo(media);
        set('logo_media_id', media?.id ?? null);
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.put('/system/website-settings', form);
            toast.success('Site settings saved.');
            reload();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    if (loading || !form) return <Spinner />;

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">Site Settings</h1>
                    <p className="text-sm text-gray-500">Brand, navigation, footer, and analytics for the public site at <span className="font-mono">/</span>.</p>
                </div>
                <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</Button>
            </div>

            <Card>
                <h2 className="mb-4 text-base font-semibold text-gray-800">Brand</h2>
                <div className="space-y-4">
                    <MediaPicker label="Logo" value={logo} onSelect={pickLogo} />
                    {errors.logo_media_id?.[0] && <p className="text-xs text-red-600">{errors.logo_media_id[0]}</p>}
                    <Input label="Tagline" name="tagline" value={form.tagline || ''} onChange={(e) => set('tagline', e.target.value)} placeholder="Shown in the footer" />
                </div>
            </Card>

            <Card>
                <h2 className="mb-4 text-base font-semibold text-gray-800">Header navigation</h2>
                <div className="space-y-2">
                    {(form.nav || []).map((link, i) => (
                        <LinkRow
                            key={i}
                            link={link}
                            onChange={(next) => set('nav', form.nav.map((l, idx) => (idx === i ? next : l)))}
                            onRemove={() => set('nav', form.nav.filter((_, idx) => idx !== i))}
                        />
                    ))}
                    <Button type="button" variant="ghost" onClick={() => set('nav', [...(form.nav || []), { label: '', href: '/' }])}>+ link</Button>
                </div>
            </Card>

            <Card>
                <h2 className="mb-4 text-base font-semibold text-gray-800">Footer columns</h2>
                <div className="space-y-4">
                    {(form.footer_columns || []).map((col, i) => (
                        <div key={i} className="rounded-lg border border-gray-200 p-3">
                            <div className="mb-2 flex gap-2">
                                <input
                                    value={col.heading || ''}
                                    onChange={(e) => set('footer_columns', form.footer_columns.map((c, idx) => (idx === i ? { ...c, heading: e.target.value } : c)))}
                                    placeholder="Column heading"
                                    className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium outline-none focus:border-[var(--accent)]"
                                />
                                <button type="button" onClick={() => set('footer_columns', form.footer_columns.filter((_, idx) => idx !== i))} className="px-1 text-red-400 hover:text-red-500">✕</button>
                            </div>
                            <div className="space-y-2">
                                {(col.links || []).map((link, j) => (
                                    <LinkRow
                                        key={j}
                                        link={link}
                                        onChange={(next) => {
                                            const links = col.links.map((l, idx) => (idx === j ? next : l));
                                            set('footer_columns', form.footer_columns.map((c, idx) => (idx === i ? { ...c, links } : c)));
                                        }}
                                        onRemove={() => {
                                            const links = col.links.filter((_, idx) => idx !== j);
                                            set('footer_columns', form.footer_columns.map((c, idx) => (idx === i ? { ...c, links } : c)));
                                        }}
                                    />
                                ))}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        const links = [...(col.links || []), { label: '', href: '/' }];
                                        set('footer_columns', form.footer_columns.map((c, idx) => (idx === i ? { ...c, links } : c)));
                                    }}
                                >
                                    + link
                                </Button>
                            </div>
                        </div>
                    ))}
                    <Button type="button" variant="ghost" onClick={() => set('footer_columns', [...(form.footer_columns || []), { heading: '', links: [] }])}>+ column</Button>
                </div>
            </Card>

            <Card>
                <h2 className="mb-4 text-base font-semibold text-gray-800">Social profiles</h2>
                <div className="space-y-2">
                    {(form.social || []).map((profile, i) => (
                        <div key={i} className="flex gap-2">
                            <input
                                value={profile.network || ''}
                                onChange={(e) => set('social', form.social.map((p, idx) => (idx === i ? { ...p, network: e.target.value } : p)))}
                                placeholder="Network (e.g. X, LinkedIn)"
                                className="w-1/3 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            <input
                                value={profile.url || ''}
                                onChange={(e) => set('social', form.social.map((p, idx) => (idx === i ? { ...p, url: e.target.value } : p)))}
                                placeholder="https://…"
                                className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            <button type="button" onClick={() => set('social', form.social.filter((_, idx) => idx !== i))} className="px-1 text-red-400 hover:text-red-500">✕</button>
                        </div>
                    ))}
                    <Button type="button" variant="ghost" onClick={() => set('social', [...(form.social || []), { network: '', url: '' }])}>+ profile</Button>
                </div>
            </Card>

            <Card>
                <h2 className="mb-4 text-base font-semibold text-gray-800">Announcement bar</h2>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="Announcement text" name="announcement" value={form.announcement || ''} onChange={(e) => set('announcement', e.target.value)} placeholder="Shown above the header (empty hides it)" />
                    <Input label="Announcement link" name="announcement_href" value={form.announcement_href || ''} onChange={(e) => set('announcement_href', e.target.value)} placeholder="/pricing" error={errors.announcement_href?.[0]} />
                </div>
            </Card>

            <Card>
                <h2 className="mb-4 text-base font-semibold text-gray-800">Analytics & contact</h2>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="Google Analytics ID" name="ga_measurement_id" value={form.ga_measurement_id || ''} onChange={(e) => set('ga_measurement_id', e.target.value)} placeholder="G-XXXXXXXXXX" error={errors.ga_measurement_id?.[0]} />
                    <Input label="Search Console verification" name="gsc_verification" value={form.gsc_verification || ''} onChange={(e) => set('gsc_verification', e.target.value)} placeholder="Verification token" />
                    <Input label="Contact email" name="contact_email" value={form.contact_email || ''} onChange={(e) => set('contact_email', e.target.value)} placeholder="hello@example.com" error={errors.contact_email?.[0]} />
                    <Input label="Sales email" name="sales_email" value={form.sales_email || ''} onChange={(e) => set('sales_email', e.target.value)} placeholder="sales@example.com" error={errors.sales_email?.[0]} />
                </div>
            </Card>

            <Card>
                <h2 className="mb-1 text-base font-semibold text-gray-800">Custom code</h2>
                <p className="mb-4 text-xs text-gray-500">Rendered raw on every public page. Only scripts you trust — this runs for all visitors.</p>
                <div className="grid gap-4">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Inside &lt;head&gt;</label>
                        <textarea value={form.custom_head || ''} onChange={(e) => set('custom_head', e.target.value)} rows={3} spellCheck={false} className="w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-xs outline-none focus:border-[var(--accent)]" />
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Before &lt;/body&gt;</label>
                        <textarea value={form.custom_body || ''} onChange={(e) => set('custom_body', e.target.value)} rows={3} spellCheck={false} className="w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-xs outline-none focus:border-[var(--accent)]" />
                    </div>
                </div>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</Button>
            </div>
        </form>
    );
}
