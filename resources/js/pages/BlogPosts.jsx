import { useEffect, useState } from 'react';
import ListSavedFilters from '../components/ui/ListSavedFilters';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Pagination from '../components/ui/Pagination';
import RichTextEditor from '../components/ui/RichTextEditor';
import MediaPicker from '../components/cms/MediaPicker';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import { usePaginated, useResource } from '../hooks/useResource';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const emptyPost = {
    slug: '',
    category_id: '',
    title: '',
    excerpt: '',
    body: '',
    cover_media_id: null,
    author_name: '',
    status: 'draft',
    seo_title: '',
    meta_description: '',
    canonical_url: '',
    noindex: false,
};

function PostFormModal({ initial, categories, onClose, onSave }) {
    const { t } = useTranslation();
    const [form, setForm] = useState(initial);
    const [cover, setCover] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (initial.cover_media_id) {
            api.get(`/system/media/${initial.cover_media_id}`)
                .then(({ data }) => setCover(data.media))
                .catch(() => setCover(null));
        } else {
            setCover(null);
        }
    }, [initial]);

    function set(field, value) {
        setForm((f) => ({ ...f, [field]: value }));
    }

    function pickCover(media) {
        setCover(media);
        set('cover_media_id', media?.id ?? null);
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await onSave({ ...form, category_id: form.category_id || null });
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open title={initial.id ? t('admin.blogPosts.editTitle', { title: initial.title }) : t('admin.blogPosts.newPost')} onClose={onClose} size="lg">
            <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label={t('admin.blogPosts.title')} name="title" value={form.title} onChange={(e) => set('title', e.target.value)} error={errors.title?.[0]} required />
                    <Input label={t('admin.blogPosts.slug')} name="slug" value={form.slug} onChange={(e) => set('slug', e.target.value)} error={errors.slug?.[0]} required />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.blogPosts.category')}</label>
                        <select value={form.category_id || ''} onChange={(e) => set('category_id', e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="">{t('admin.blogPosts.uncategorized')}</option>
                            {(categories || []).map((c) => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.common.status')}</label>
                        <select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="draft">{t('admin.cmsPages.draft')}</option>
                            <option value="published">{t('admin.cmsPages.published')}</option>
                        </select>
                    </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label={t('admin.blogPosts.author')} name="author_name" value={form.author_name || ''} onChange={(e) => set('author_name', e.target.value)} placeholder={t('admin.blogPosts.authorPlaceholder')} />
                    <Input label={t('admin.blogPosts.excerpt')} name="excerpt" value={form.excerpt || ''} onChange={(e) => set('excerpt', e.target.value)} placeholder={t('admin.blogPosts.excerptPlaceholder')} />
                </div>
                <MediaPicker label={t('admin.blogPosts.coverImage')} value={cover} onSelect={pickCover} />
                <div>
                    <p className="mb-1.5 text-sm font-medium text-gray-700">{t('admin.blogPosts.body')}</p>
                    <RichTextEditor value={form.body || ''} onChange={(body) => set('body', body)} placeholder={t('admin.blogPosts.bodyPlaceholder')} />
                </div>
                <div className="rounded-lg bg-gray-50 p-3">
                    <p className="mb-3 text-sm font-semibold text-gray-700">{t('admin.cmsPages.seo')}</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label={t('admin.cmsPages.seoTitle')} name="seo_title" value={form.seo_title || ''} onChange={(e) => set('seo_title', e.target.value)} />
                        <Input label={t('admin.cmsPages.canonicalUrl')} name="canonical_url" value={form.canonical_url || ''} onChange={(e) => set('canonical_url', e.target.value)} error={errors.canonical_url?.[0]} />
                    </div>
                    <div className="mt-4">
                        <Input label={t('admin.cmsPages.metaDescription')} name="meta_description" value={form.meta_description || ''} onChange={(e) => set('meta_description', e.target.value)} />
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={!!form.noindex} onChange={(e) => set('noindex', e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                        {t('admin.cmsPages.noindex')}
                    </label>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="ghost" onClick={onClose} disabled={saving}>{t('admin.common.cancel')}</Button>
                    <Button type="submit" disabled={saving}>{saving ? t('admin.common.saving') : t('admin.blogPosts.savePost')}</Button>
                </div>
            </form>
        </Modal>
    );
}

function CategoryManager({ categories, onChanged }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');

    async function add(e) {
        e.preventDefault();
        try {
            await api.post('/system/blog-categories', { name, slug });
            toast.success(t('admin.blogPosts.categoryAdded'));
            setName('');
            setSlug('');
            onChanged();
        } catch (err) {
            toast.error(fieldErrors(err).slug?.[0] || fieldErrors(err).name?.[0] || t('admin.blogPosts.categoryAddFailed'));
        }
    }

    async function remove(category) {
        try {
            await api.delete(`/system/blog-categories/${category.id}`);
            toast.success(t('admin.blogPosts.categoryDeleted'));
            onChanged();
        } catch {
            toast.error(t('admin.blogPosts.categoryDeleteFailed'));
        }
    }

    return (
        <Card>
            <h2 className="mb-3 text-base font-semibold text-gray-800">{t('admin.blogPosts.categories')}</h2>
            <div className="mb-3 flex flex-wrap gap-2">
                {(categories || []).map((c) => (
                    <span key={c.id} className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1.5 text-xs font-medium text-gray-700">
                        {c.name} <span className="text-gray-400">({c.posts_count})</span>
                        <button type="button" onClick={() => remove(c)} className="rounded-full px-1 text-gray-400 hover:text-red-500">✕</button>
                    </span>
                ))}
                {(categories || []).length === 0 && <p className="text-xs text-gray-400">{t('admin.blogPosts.noCategories')}</p>}
            </div>
            <form onSubmit={add} className="flex gap-2">
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('admin.common.name')} required className="w-1/2 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]" />
                <input value={slug} onChange={(e) => setSlug(e.target.value)} placeholder={t('admin.blogPosts.slugPlaceholder')} required className="w-1/2 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-[var(--accent)]" />
                <Button type="submit">{t('admin.blogPosts.addCategory')}</Button>
            </form>
        </Card>
    );
}

export default function BlogPosts() {
    const { t } = useTranslation();
    usePageTitle(t('admin.blogPosts.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.blogPosts.crumbBlog') }]);
    const toast = useToast();
    const confirm = useConfirm();
    const [status, setStatus] = useState('');
    const { data, loading, reload, page, setPage } = usePaginated(
        (p, signal) => api.get('/system/blog-posts', { signal, params: { page: p, status: status || undefined, per_page: 15 } }).then(({ data }) => data),
        [status],
    );
    const { data: categories = [], reload: reloadCategories } = useResource((signal) =>
        api.get('/system/blog-categories', { signal }).then(({ data }) => data.categories),
    );
    const [editor, setEditor] = useState(null);
    const posts = data?.posts || [];
    const pagination = data?.pagination;

    async function openEditor(post) {
        try {
            const { data: full } = await api.get(`/system/blog-posts/${post.id}`);
            setEditor({ ...emptyPost, ...full.post });
        } catch {
            toast.error(t('admin.blogPosts.loadFailed'));
        }
    }

    async function save(form) {
        if (form.id) {
            await api.put(`/system/blog-posts/${form.id}`, form);
            toast.success(t('admin.blogPosts.updated'));
        } else {
            await api.post('/system/blog-posts', form);
            toast.success(t('admin.blogPosts.created'));
        }
        setEditor(null);
        reload();
    }

    async function publish(post) {
        await api.post(`/system/blog-posts/${post.id}/publish`);
        toast.success(t('admin.blogPosts.publishedToast'));
        reload();
    }

    async function unpublish(post) {
        await api.post(`/system/blog-posts/${post.id}/unpublish`);
        toast.success(t('admin.blogPosts.takenDown'));
        reload();
    }

    async function openPreview(post) {
        try {
            const { data: full } = await api.get(`/system/blog-posts/${post.id}`);
            if (full.post.preview_url) {
                window.open(full.post.preview_url, '_blank', 'noopener');
            } else {
                toast.error(t('admin.blogPosts.previewNotAvailable'));
            }
        } catch {
            toast.error(t('admin.blogPosts.previewFailed'));
        }
    }

    async function remove(post) {
        if (!(await confirm({ title: t('admin.blogPosts.deleteTitle'), message: t('admin.cmsPages.deleteMessage', { title: post.title }), variant: 'danger' }))) return;
        await api.delete(`/system/blog-posts/${post.id}`);
        toast.success(t('admin.blogPosts.deleted'));
        reload();
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">{t('admin.blogPosts.pageTitle')}</h1>
                    <p className="text-sm text-gray-500">{t('admin.blogPosts.subtitlePrefix')} <span className="font-mono">/blog</span>{t('admin.blogPosts.subtitleSuffix')}</p>
                </div>
                <Button onClick={() => setEditor(emptyPost)}>{t('admin.blogPosts.newPost')}</Button>
            </div>

            <CategoryManager categories={categories} onChanged={reloadCategories} />

            <Card>
                <div className="mb-4 flex max-w-xs items-end gap-2">
                    <div className="flex-1">
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('admin.common.status')}</label>
                        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="">{t('admin.blogPosts.all')}</option>
                            <option value="published">{t('admin.cmsPages.published')}</option>
                            <option value="draft">{t('admin.cmsPages.draft')}</option>
                        </select>
                    </div>
                    <ListSavedFilters page="platform-blog" scope="system" value={{ status }} onApply={(saved) => { setStatus(saved.status || ''); setPage(1); }} />
                </div>
                {loading ? (
                    <ContentSkeleton size="sm" />
                ) : (
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th scope="col" className="pb-2 font-semibold">{t('admin.blogPosts.thPost')}</th>
                                <th scope="col" className="pb-2 font-semibold">{t('admin.blogPosts.category')}</th>
                                <th scope="col" className="pb-2 font-semibold">{t('admin.common.status')}</th>
                                <th scope="col" className="pb-2 text-right font-semibold">{t('admin.common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {posts.map((post) => (
                                <tr key={post.id} className="border-t border-gray-100">
                                    <td className="py-2.5">
                                        <p className="font-medium text-gray-800">{post.title}</p>
                                        <p className="font-mono text-xs text-gray-400">/blog/{post.slug}</p>
                                    </td>
                                    <td className="py-2.5 text-gray-500">{post.category?.name || '—'}</td>
                                    <td className="py-2.5"><Badge>{post.status}</Badge></td>
                                    <td className="py-2.5">
                                        <div className="flex justify-end gap-2">
                                            <button type="button" onClick={() => openPreview(post)} className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">{t('admin.cmsPages.preview')}</button>
                                            <button type="button" onClick={() => openEditor(post)} className="text-gray-500 hover:text-gray-700">{t('admin.cmsPages.edit')}</button>
                                            {post.status === 'published' ? (
                                                <button type="button" onClick={() => unpublish(post)} className="text-amber-600 hover:text-amber-500">{t('admin.cmsPages.unpublish')}</button>
                                            ) : (
                                                <button type="button" onClick={() => publish(post)} className="text-green-600 hover:text-green-500">{t('admin.cmsPages.publish')}</button>
                                            )}
                                            <button type="button" onClick={() => remove(post)} className="text-red-500 hover:text-red-400">{t('admin.cmsPages.delete')}</button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {posts.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">{t('admin.blogPosts.noPosts')}</td></tr>
                            )}
                        </tbody>
                    </table></div>
                )}
                {pagination && (
                    <div className="mt-4">
                        <Pagination page={page} pages={pagination.last_page} total={pagination.total} onChange={setPage} />
                    </div>
                )}
            </Card>

            {editor && <PostFormModal initial={editor} categories={categories} onClose={() => setEditor(null)} onSave={save} />}
        </div>
    );
}
