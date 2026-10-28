import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Button from '../components/ui/Button';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useResource } from '../hooks/useResource';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import PageFormModal from '../components/cms/PageFormModal';
import { emptyForm } from '../components/cms/emptyPageForm';

export default function CmsPages() {
    const { t } = useTranslation();
    usePageTitle(t('admin.cmsPages.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.cmsPages.crumbWebsite') }]);
    const toast = useToast();
    const confirm = useConfirm();
    const { data: pages = [], loading, reload: fetchPages } = useResource((signal) =>
        api.get('/system/pages', { signal }).then(({ data }) => data.pages),
    );
    const [editor, setEditor] = useState(null);

    // The list payload carries no content blocks — always open the full page
    // (which also yields the signed preview URL), or edits would save back
    // an empty block list and wipe the page.
    async function openEditor(page) {
        try {
            const { data } = await api.get(`/system/pages/${page.id}`);
            setEditor({ ...emptyForm, ...data.page });
        } catch {
            toast.error(t('admin.cmsPages.loadFailed'));
        }
    }

    async function openPreview(page) {
        try {
            const { data } = await api.get(`/system/pages/${page.id}`);
            window.open(data.page.preview_url, '_blank', 'noopener');
        } catch {
            toast.error(t('admin.cmsPages.previewFailed'));
        }
    }

    async function duplicate(page) {
        try {
            const { data } = await api.post(`/system/pages/${page.id}/duplicate`);
            toast.success(t('admin.cmsPages.duplicated', { slug: data.page.slug }));
            fetchPages();
        } catch (e) {
            toast.error(fieldErrors(e).form || t('admin.cmsPages.duplicateFailed'));
        }
    }

    async function save(form) {
        const payload = { ...form };
        if (form.id) {
            await api.put(`/system/pages/${form.id}`, payload);
            toast.success(t('admin.cmsPages.updated'));
            setEditor(null);
        } else {
            const { data } = await api.post('/system/pages', payload);
            toast.success(t('admin.cmsPages.created', { title: data.page.title }));
            setEditor(null);
        }
        fetchPages();
    }

    async function publish(page) {
        await api.post(`/system/pages/${page.id}/publish`);
        toast.success(t('admin.cmsPages.publishedToast'));
        fetchPages();
    }

    async function unpublish(page) {
        await api.post(`/system/pages/${page.id}/unpublish`);
        toast.success(t('admin.cmsPages.takenDown'));
        fetchPages();
    }

    async function remove(page) {
        if (!(await confirm({ title: t('admin.cmsPages.deleteTitle'), message: t('admin.cmsPages.deleteMessage', { title: page.title }), variant: 'danger' }))) return;
        try {
            await api.delete(`/system/pages/${page.id}`);
            toast.success(t('admin.cmsPages.deleted'));
            fetchPages();
        } catch (e) {
            toast.error(fieldErrors(e).form || t('admin.cmsPages.deleteFailed'));
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">{t('admin.cmsPages.pageTitle')}</h1>
                    <p className="text-sm text-gray-500">{t('admin.cmsPages.subtitlePrefix')} <span className="font-mono">/</span>{t('admin.cmsPages.subtitleSuffix')}</p>
                </div>
                <Button onClick={() => setEditor(emptyForm)}>{t('admin.cmsPages.newPage')}</Button>
            </div>

            <Card>
                {loading ? (
                    <ContentSkeleton size="sm" />
                ) : (
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th scope="col" className="pb-2 font-semibold">{t('admin.cmsPages.thPage')}</th>
                                <th scope="col" className="pb-2 font-semibold">{t('admin.common.status')}</th>
                                <th scope="col" className="pb-2 font-semibold">{t('admin.cmsPages.thSlug')}</th>
                                <th scope="col" className="pb-2 text-right font-semibold">{t('admin.common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pages.map((page) => (
                                <tr key={page.id} className="border-t border-gray-100">
                                    <td className="py-2.5 font-medium text-gray-800">{page.title}</td>
                                    <td className="py-2.5"><Badge>{page.status}</Badge></td>
                                    <td className="py-2.5">
                                        <span className="font-mono text-xs text-gray-500">
                                            {page.slug === 'home' ? '/' : `/${page.slug}`}
                                        </span>
                                    </td>
                                    <td className="py-2.5">
                                        <div className="flex justify-end gap-2">
                                            <a href={page.slug === 'home' ? '/' : `/${page.slug}`} target="_blank" rel="noreferrer" className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">{t('admin.cmsPages.view')}</a>
                                            <button type="button" onClick={() => openPreview(page)} className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">{t('admin.cmsPages.preview')}</button>
                                            <button type="button" onClick={() => openEditor(page)} className="text-gray-500 hover:text-gray-700">{t('admin.cmsPages.edit')}</button>
                                            <button type="button" onClick={() => duplicate(page)} className="text-gray-500 hover:text-gray-700">{t('admin.cmsPages.duplicate')}</button>
                                            {page.status === 'published' ? (
                                                <button type="button" onClick={() => unpublish(page)} className="text-amber-600 hover:text-amber-500">{t('admin.cmsPages.unpublish')}</button>
                                            ) : (
                                                <button type="button" onClick={() => publish(page)} className="text-green-600 hover:text-green-500">{t('admin.cmsPages.publish')}</button>
                                            )}
                                            <button type="button" onClick={() => remove(page)} className="text-red-500 hover:text-red-400">{t('admin.cmsPages.delete')}</button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {pages.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">{t('admin.cmsPages.noPages')}</td></tr>
                            )}
                        </tbody>
                    </table></div>
                )}
            </Card>

            {editor && <PageFormModal initial={editor} onClose={() => setEditor(null)} onSave={save} />}
        </div>
    );
}