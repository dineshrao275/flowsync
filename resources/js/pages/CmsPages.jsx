import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Badge from '../components/ui/Badge';
import Spinner from '../components/ui/Spinner';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

const emptyForm = {
    slug: '',
    title: '',
    status: 'draft',
    content: [],
    seo_title: '',
    meta_description: '',
    og_image: '',
    sitemap_include: true,
    sort_order: 0,
};

function BlockEditor({ blocks, onChange }) {
    const emptyBlock = { type: 'text', heading: '', body: '' };

    function update(i, patch) {
        const next = blocks.map((b, idx) => (idx === i ? { ...b, ...patch } : b));
        onChange(next);
    }

    function remove(i) {
        onChange(blocks.filter((_, idx) => idx !== i));
    }

    function move(i, dir) {
        const next = [...blocks];
        const j = i + dir;
        if (j < 0 || j >= next.length) return;
        [next[i], next[j]] = [next[j], next[i]];
        onChange(next);
    }

    function add(type) {
        const b = { type, heading: '', body: '', subtext: '', items: [], cta: { label: '', href: '/app/register' } };
        onChange([...blocks, b]);
    }

    return (
        <div className="space-y-3">
            {blocks.map((b, i) => (
                <div key={i} className="rounded-lg border border-gray-200 p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="font-mono text-xs font-semibold uppercase text-indigo-600">{b.type}</span>
                        <span className="flex gap-1">
                            <button type="button" onClick={() => move(i, -1)} className="rounded px-1.5 text-gray-400 hover:bg-gray-100">↑</button>
                            <button type="button" onClick={() => move(i, 1)} className="rounded px-1.5 text-gray-400 hover:bg-gray-100">↓</button>
                            <button type="button" onClick={() => remove(i)} className="rounded px-1.5 text-red-400 hover:bg-red-50">✕</button>
                        </span>
                    </div>
                    <input
                        value={b.heading || ''}
                        onChange={(e) => update(i, { heading: e.target.value })}
                        placeholder="Heading"
                        className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-indigo-400"
                    />
                    {b.type === 'hero' && (
                        <input
                            value={b.subtext || ''}
                            onChange={(e) => update(i, { subtext: e.target.value })}
                            placeholder="Subtext"
                            className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-indigo-400"
                        />
                    )}
                    {b.type === 'text' && (
                        <textarea
                            value={b.body || ''}
                            onChange={(e) => update(i, { body: e.target.value })}
                            placeholder="Body (paragraphs separated by blank lines)"
                            rows={3}
                            className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-indigo-400"
                        />
                    )}
                    {b.type === 'cta' && (
                        <input
                            value={b.text || ''}
                            onChange={(e) => update(i, { text: e.target.value })}
                            placeholder="Supporting text"
                            className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-indigo-400"
                        />
                    )}
                    {b.cta && (
                        <div className="flex gap-2">
                            <input
                                value={b.cta?.label || ''}
                                onChange={(e) => update(i, { cta: { ...b.cta, label: e.target.value } })}
                                placeholder="Call to action label"
                                className="w-1/2 rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-indigo-400"
                            />
                            <input
                                value={b.cta?.href || ''}
                                onChange={(e) => update(i, { cta: { ...b.cta, href: e.target.value } })}
                                placeholder="Link (e.g. /app/register)"
                                className="w-1/2 rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-indigo-400"
                            />
                        </div>
                    )}
                    {b.type === 'features' && (
                        <div className="space-y-2">
                            {b.items.map((item, j) => (
                                <div key={j} className="flex gap-2">
                                    <input
                                        value={item.title || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, title: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Feature title"
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <input
                                        value={item.text || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, text: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Description"
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...b.items, { title: '', text: '' }] })}>+ feature</Button>
                        </div>
                    )}
                </div>
            ))}
            <div className="flex flex-wrap gap-2">
                {['hero', 'features', 'text', 'cta'].map((t) => (
                    <Button key={t} type="button" variant="ghost" onClick={() => add(t)}>+ {t}</Button>
                ))}
            </div>
        </div>
    );
}

function PageFormModal({ initial, onClose, onSave }) {
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
        <Modal open title={initial.id ? `Edit ${initial.title}` : 'New page'} onClose={onClose} size="lg">
            <form onSubmit={submit} className="space-y-4 px-6 pb-6 pt-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="Title" name="title" value={form.title} onChange={(e) => set('title', e.target.value)} error={errors.title?.[0]} required />
                    <Input label="Slug" name="slug" value={form.slug} onChange={(e) => set('slug', e.target.value)} error={errors.slug?.[0]} required disabled={form.slug === 'home'} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Status</label>
                        <select value={form.status} onChange={(e) => set('status', e.target.value)} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm text-gray-700">
                        <input type="checkbox" checked={!!form.sitemap_include} onChange={(e) => set('sitemap_include', e.target.checked)} className="h-4 w-4 rounded border-gray-300 text-indigo-600" />
                        Include in sitemap
                    </label>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="SEO title" name="seo_title" value={form.seo_title || ''} onChange={(e) => set('seo_title', e.target.value)} />
                    <Input label="OG image URL" name="og_image" value={form.og_image || ''} onChange={(e) => set('og_image', e.target.value)} />
                </div>
                <Input label="Meta description" name="meta_description" value={form.meta_description || ''} onChange={(e) => set('meta_description', e.target.value)} error={errors.meta_description?.[0]} />
                <div>
                    <p className="mb-1.5 text-sm font-medium text-gray-700">Content blocks</p>
                    <BlockEditor blocks={form.content || []} onChange={(content) => set('content', content)} />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="ghost" onClick={onClose} disabled={saving}>Cancel</Button>
                    <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save page'}</Button>
                </div>
            </form>
        </Modal>
    );
}

export default function CmsPages() {
    usePageTitle('Website Pages');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Website' }]);
    const { toast } = useToast();
    const [pages, setPages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editor, setEditor] = useState(null);

    const fetchPages = () => {
        setLoading(true);
        api.get('/system/pages')
            .then(({ data }) => setPages(data.pages))
            .finally(() => setLoading(false));
    };

    useEffect(fetchPages, []);

    async function save(form) {
        const payload = { ...form };
        if (form.id) {
            const { data } = await api.put(`/system/pages/${form.id}`, payload);
            toast.success('Page updated.');
            setEditor(null);
        } else {
            const { data } = await api.post('/system/pages', payload);
            toast.success(`Created ${data.page.title}.`);
            setEditor(null);
        }
        fetchPages();
    }

    async function publish(page) {
        await api.post(`/system/pages/${page.id}/publish`);
        toast.success('Page published.');
        fetchPages();
    }

    async function unpublish(page) {
        await api.post(`/system/pages/${page.id}/unpublish`);
        toast.success('Page taken down.');
        fetchPages();
    }

    async function remove(page) {
        if (!confirm(`Delete "${page.title}"?`)) return;
        try {
            await api.delete(`/system/pages/${page.id}`);
            toast.success('Page deleted.');
            fetchPages();
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Cannot delete this page.');
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">Website Pages</h1>
                    <p className="text-sm text-gray-500">Server-rendered marketing pages for the public site at <span className="font-mono">/</span>.</p>
                </div>
                <Button onClick={() => setEditor(emptyForm)}>New page</Button>
            </div>

            <Card>
                {loading ? (
                    <Spinner />
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-gray-400">
                            <tr>
                                <th className="pb-2 font-semibold">Page</th>
                                <th className="pb-2 font-semibold">Status</th>
                                <th className="pb-2 font-semibold">Slug</th>
                                <th className="pb-2 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pages.map((page) => (
                                <tr key={page.id} className="border-t border-gray-100">
                                    <td className="py-2.5 font-medium text-gray-800">{page.title}</td>
                                    <td className="py-2.5"><Badge>{page.status}</Badge></td>
                                    <td className="py-2.5">
                                        <span className="font-mono text-xs text-gray-500">
                                            {page.slug === 'home' ? '/' : `/page/${page.slug}`}
                                        </span>
                                    </td>
                                    <td className="py-2.5">
                                        <div className="flex justify-end gap-2">
                                            <a href={page.slug === 'home' ? '/' : `/page/${page.slug}`} target="_blank" rel="noreferrer" className="text-indigo-600 hover:text-indigo-500">view</a>
                                            <button type="button" onClick={() => setEditor({ ...emptyForm, ...page, content: page.content || [] })} className="text-gray-500 hover:text-gray-700">edit</button>
                                            {page.status === 'published' ? (
                                                <button type="button" onClick={() => unpublish(page)} className="text-amber-600 hover:text-amber-500">unpublish</button>
                                            ) : (
                                                <button type="button" onClick={() => publish(page)} className="text-green-600 hover:text-green-500">publish</button>
                                            )}
                                            <button type="button" onClick={() => remove(page)} className="text-red-500 hover:text-red-400">delete</button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {pages.length === 0 && (
                                <tr><td colSpan={4} className="py-6 text-center text-gray-400">No pages yet.</td></tr>
                            )}
                        </tbody>
                    </table>
                )}
            </Card>

            {editor && <PageFormModal initial={editor} onClose={() => setEditor(null)} onSave={save} />}
        </div>
    );
}