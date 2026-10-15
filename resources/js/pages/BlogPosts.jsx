import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import Spinner from '../components/ui/Spinner';
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
        <Modal open title={initial.id ? `Edit ${initial.title}` : 'New post'} onClose={onClose} size="lg">
            <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="Title" name="title" value={form.title} onChange={(e) => set('title', e.target.value)} error={errors.title?.[0]} required />
                    <Input label="Slug" name="slug" value={form.slug} onChange={(e) => set('slug', e.target.value)} error={errors.slug?.[0]} required />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Category</label>
                        <select value={form.category_id || ''} onChange={(e) => set('category_id', e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="">Uncategorized</option>
                            {(categories || []).map((c) => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
                        <select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="Author" name="author_name" value={form.author_name || ''} onChange={(e) => set('author_name', e.target.value)} placeholder="Team FlowSync" />
                    <Input label="Excerpt" name="excerpt" value={form.excerpt || ''} onChange={(e) => set('excerpt', e.target.value)} placeholder="One-line summary" />
                </div>
                <MediaPicker label="Cover image" value={cover} onSelect={pickCover} />
                <div>
                    <p className="mb-1.5 text-sm font-medium text-gray-700">Body</p>
                    <RichTextEditor value={form.body || ''} onChange={(body) => set('body', body)} placeholder="Write the post…" />
                </div>
                <div className="rounded-lg bg-gray-50 p-3">
                    <p className="mb-3 text-sm font-semibold text-gray-700">SEO</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label="SEO title" name="seo_title" value={form.seo_title || ''} onChange={(e) => set('seo_title', e.target.value)} />
                        <Input label="Canonical URL" name="canonical_url" value={form.canonical_url || ''} onChange={(e) => set('canonical_url', e.target.value)} error={errors.canonical_url?.[0]} />
                    </div>
                    <div className="mt-4">
                        <Input label="Meta description" name="meta_description" value={form.meta_description || ''} onChange={(e) => set('meta_description', e.target.value)} />
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={!!form.noindex} onChange={(e) => set('noindex', e.target.checked)} className="h-4 w-4 rounded border-gray-300 text-indigo-600" />
                        Hide from search engines (noindex, excluded from sitemap)
                    </label>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="ghost" onClick={onClose} disabled={saving}>Cancel</Button>
                    <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save post'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function CategoryManager({ categories, onChanged }) {
    const toast = useToast();
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');

    async function add(e) {
        e.preventDefault();
        try {
            await api.post('/system/blog-categories', { name, slug });
            toast.success('Category added.');
            setName('');
            setSlug('');
            onChanged();
        } catch (err) {
            toast.error(fieldErrors(err).slug?.[0] || fieldErrors(err).name?.[0] || 'Could not add the category.');
        }
    }

    async function remove(category) {
        try {
            await api.delete(`/system/blog-categories/${category.id}`);
            toast.success('Category deleted.');
            onChanged();
        } catch {
            toast.error('Move or delete the category posts first.');
        }
    }

    return (
        <Card>
            <h2 className="mb-3 text-base font-semibold text-gray-800">Categories</h2>
            <div className="mb-3 flex flex-wrap gap-2">
                {(categories || []).map((c) => (
                    <span key={c.id} className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 py-1 pl-3 pr-1.5 text-xs font-medium text-gray-700">
                        {c.name} <span className="text-gray-400">({c.posts_count})</span>
                        <button type="button" onClick={() => remove(c)} className="rounded-full px-1 text-gray-400 hover:text-red-500">✕</button>
                    </span>
                ))}
                {(categories || []).length === 0 && <p className="text-xs text-gray-400">No categories yet.</p>}
            </div>
            <form onSubmit={add} className="flex gap-2">
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name" required className="w-1/2 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-indigo-400" />
                <input value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="slug" required className="w-1/2 rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none focus:border-indigo-400" />
                <Button type="submit">Add</Button>
            </form>
        </Card>
    );
}

export default function BlogPosts() {
    usePageTitle('Blog');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Blog' }]);
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
            toast.error('Could not load the post.');
        }
    }

    async function save(form) {
        if (form.id) {
            await api.put(`/system/blog-posts/${form.id}`, form);
            toast.success('Post updated.');
        } else {
            await api.post('/system/blog-posts', form);
            toast.success('Post created.');
        }
        setEditor(null);
        reload();
    }

    async function publish(post) {
        await api.post(`/system/blog-posts/${post.id}/publish`);
        toast.success('Post published.');
        reload();
    }

    async function unpublish(post) {
        await api.post(`/system/blog-posts/${post.id}/unpublish`);
        toast.success('Post taken down.');
        reload();
    }

    async function openPreview(post) {
        try {
            const { data: full } = await api.get(`/system/blog-posts/${post.id}`);
            if (full.post.preview_url) {
                window.open(full.post.preview_url, '_blank', 'noopener');
            } else {
                toast.error('Preview is not available yet.');
            }
        } catch {
            toast.error('Could not build the preview link.');
        }
    }

    async function remove(post) {
        if (!(await confirm({ title: 'Delete post?', message: `Delete "${post.title}"? This cannot be undone.`, variant: 'danger' }))) return;
        await api.delete(`/system/blog-posts/${post.id}`);
        toast.success('Post deleted.');
        reload();
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">Blog</h1>
                    <p className="text-sm text-gray-500">Posts for the public blog at <span className="font-mono">/blog</span>.</p>
                </div>
                <Button onClick={() => setEditor(emptyPost)}>New post</Button>
            </div>

            <CategoryManager categories={categories} onChanged={reloadCategories} />

            <Card>
                <div className="mb-4 flex max-w-xs items-end gap-2">
                    <div className="flex-1">
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
                        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="">All</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>
                </div>
                {loading ? (
                    <Spinner />
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th className="pb-2 font-semibold">Post</th>
                                <th className="pb-2 font-semibold">Category</th>
                                <th className="pb-2 font-semibold">Status</th>
                                <th className="pb-2 text-right font-semibold">Actions</th>
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
                                            <button type="button" onClick={() => openPreview(post)} className="text-indigo-600 hover:text-indigo-500">preview</button>
                                            <button type="button" onClick={() => openEditor(post)} className="text-gray-500 hover:text-gray-700">edit</button>
                                            {post.status === 'published' ? (
                                                <button type="button" onClick={() => unpublish(post)} className="text-amber-600 hover:text-amber-500">unpublish</button>
                                            ) : (
                                                <button type="button" onClick={() => publish(post)} className="text-green-600 hover:text-green-500">publish</button>
                                            )}
                                            <button type="button" onClick={() => remove(post)} className="text-red-500 hover:text-red-400">delete</button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {posts.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">No posts yet.</td></tr>
                            )}
                        </tbody>
                    </table>
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
