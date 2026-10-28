import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import BlockEditor from './BlockEditor';

export default function PageFormModal({ initial, onClose, onSave }) {
    const { t } = useTranslation();
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    function set(field, value) {
        setForm((f) => ({ ...f, [field]: value }));
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await onSave(form);
        } catch (e) {
            setErrors(fieldErrors(e));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open title={initial.id ? t('admin.cmsPages.editTitle', { title: initial.title }) : t('admin.cmsPages.newPage')} onClose={onClose} size="lg">
            <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label={t('admin.cmsPages.title')} name="title" value={form.title} onChange={(e) => set('title', e.target.value)} error={errors.title?.[0]} required />
                    <Input label={t('admin.cmsPages.slug')} name="slug" value={form.slug} onChange={(e) => set('slug', e.target.value)} error={errors.slug?.[0]} required disabled={form.slug === 'home'} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.common.status')}</label>
                        <select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="draft">{t('admin.cmsPages.draft')}</option>
                            <option value="published">{t('admin.cmsPages.published')}</option>
                        </select>
                    </div>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm text-gray-700">
                        <input type="checkbox" checked={!!form.sitemap_include} onChange={(e) => set('sitemap_include', e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                        {t('admin.cmsPages.includeInSitemap')}
                    </label>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label={t('admin.cmsPages.seoTitle')} name="seo_title" value={form.seo_title || ''} onChange={(e) => set('seo_title', e.target.value)} />
                    <Input label={t('admin.cmsPages.ogImageUrl')} name="og_image" value={form.og_image || ''} onChange={(e) => set('og_image', e.target.value)} />
                </div>
                <Input label={t('admin.cmsPages.metaDescription')} name="meta_description" value={form.meta_description || ''} onChange={(e) => set('meta_description', e.target.value)} error={errors.meta_description?.[0]} />
                <div className="rounded-lg bg-gray-50 p-3">
                    <p className="mb-3 text-sm font-semibold text-gray-700">{t('admin.cmsPages.seo')}</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label={t('admin.cmsPages.ogTitle')} name="og_title" value={form.og_title || ''} onChange={(e) => set('og_title', e.target.value)} />
                        <Input label={t('admin.cmsPages.canonicalUrl')} name="canonical_url" value={form.canonical_url || ''} onChange={(e) => set('canonical_url', e.target.value)} error={errors.canonical_url?.[0]} />
                    </div>
                    <div className="mt-4">
                        <Input label={t('admin.cmsPages.ogDescription')} name="og_description" value={form.og_description || ''} onChange={(e) => set('og_description', e.target.value)} />
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={!!form.noindex} onChange={(e) => set('noindex', e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                        {t('admin.cmsPages.noindex')}
                    </label>
                </div>
                <div>
                    <p className="mb-1.5 text-sm font-medium text-gray-700">{t('admin.cmsPages.contentBlocks')}</p>
                    <BlockEditor blocks={form.content || []} onChange={(content) => set('content', content)} />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="ghost" onClick={onClose} disabled={saving}>{t('admin.common.cancel')}</Button>
                    <Button type="submit" disabled={saving}>{saving ? t('admin.common.saving') : t('admin.cmsPages.savePage')}</Button>
                </div>
            </form>
        </Modal>
    );
}
