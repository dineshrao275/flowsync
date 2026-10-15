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
import { useResource } from '../hooks/useResource';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import RichTextEditor from '../components/ui/RichTextEditor';
import MediaPicker from '../components/cms/MediaPicker';

const emptyForm = {
    slug: '',
    title: '',
    status: 'draft',
    content: [],
    seo_title: '',
    meta_description: '',
    og_image: '',
    canonical_url: '',
    noindex: false,
    og_title: '',
    og_description: '',
    sitemap_include: true,
    sort_order: 0,
};

const BLOCK_TYPES = ['hero', 'logo_cloud', 'features', 'split', 'steps', 'stats', 'testimonials', 'team', 'video', 'pricing', 'faq', 'comparison', 'contact_form', 'text', 'richtext', 'cta'];

// Media id ↔ picker object bridge: blocks store the id, the picker needs the
// row. Hydrated once per id; a deleted image resolves to null (unpicked).
function ImageField({ mediaId, onChange }) {
    const [media, setMedia] = useState(null);

    useEffect(() => {
        if (!mediaId) {
            setMedia(null);
            return;
        }
        let live = true;
        api.get(`/system/media/${mediaId}`)
            .then(({ data }) => { if (live) setMedia(data.media); })
            .catch(() => { if (live) setMedia(null); });
        return () => { live = false; };
    }, [mediaId]);

    return <MediaPicker label="Image" value={media} onSelect={(m) => onChange(m?.id ?? null)} />;
}

function textInput(value, onChange, placeholder) {
    return (
        <input
            value={value || ''}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
        />
    );
}

function BlockEditor({ blocks, onChange }) {

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
                        <span className="font-mono text-xs font-semibold uppercase text-[var(--accent-soft-text)]">{b.type}</span>
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
                        className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                    />
                    {b.type === 'hero' && (
                        <input
                            value={b.subtext || ''}
                            onChange={(e) => update(i, { subtext: e.target.value })}
                            placeholder="Subtext"
                            className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                        />
                    )}
                    {b.type === 'text' && (
                        <textarea
                            value={b.body || ''}
                            onChange={(e) => update(i, { body: e.target.value })}
                            placeholder="Body (paragraphs separated by blank lines)"
                            rows={3}
                            className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                        />
                    )}
                    {b.type === 'richtext' && (
                        <RichTextEditor
                            value={b.html || ''}
                            onChange={(html) => update(i, { html })}
                            placeholder="Formatted body (headings, lists, links…)"
                        />
                    )}
                    {b.type === 'cta' && (
                        <input
                            value={b.text || ''}
                            onChange={(e) => update(i, { text: e.target.value })}
                            placeholder="Supporting text"
                            className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                        />
                    )}
                    {['steps', 'split', 'testimonials', 'team', 'pricing', 'faq', 'contact_form'].includes(b.type) && (
                        <div className="mb-2 grid gap-2 sm:grid-cols-2">
                            <input
                                value={b.eyebrow || ''}
                                onChange={(e) => update(i, { eyebrow: e.target.value })}
                                placeholder="Eyebrow (small pill label)"
                                className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            {b.type !== 'split' && (
                                <input
                                    value={b.lead || ''}
                                    onChange={(e) => update(i, { lead: e.target.value })}
                                    placeholder="Lead paragraph"
                                    className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                                />
                            )}
                        </div>
                    )}
                    {b.type === 'logo_cloud' && (
                        <div className="space-y-2">
                            {(b.logos || []).map((logo, j) => (
                                <div key={j} className="flex items-center gap-2">
                                    <div className="w-1/2">
                                        <ImageField mediaId={logo.media_id} onChange={(media_id) => {
                                            const logos = b.logos.map((it, idx) => (idx === j ? { ...it, media_id } : it));
                                            update(i, { logos });
                                        }} />
                                    </div>
                                    <input
                                        value={logo.name || ''}
                                        onChange={(e) => {
                                            const logos = b.logos.map((it, idx) => (idx === j ? { ...it, name: e.target.value } : it));
                                            update(i, { logos });
                                        }}
                                        placeholder="Company name (when no logo image)"
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { logos: b.logos.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { logos: [...(b.logos || []), { name: '', media_id: null }] })}>+ logo</Button>
                        </div>
                    )}
                    {b.type === 'stats' && (
                        <div className="space-y-2">
                            {(b.items || []).map((stat, j) => (
                                <div key={j} className="flex gap-2">
                                    <input
                                        value={stat.value || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, value: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Value (e.g. 99.99%)"
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <input
                                        value={stat.label || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, label: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Label"
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { value: '', label: '' }] })}>+ stat</Button>
                        </div>
                    )}
                    {b.type === 'steps' && (
                        <div className="space-y-2">
                            {(b.items || []).map((step, j) => (
                                <div key={j} className="flex gap-2">
                                    <input
                                        value={step.title || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, title: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Step title"
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <input
                                        value={step.text || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, text: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="What happens"
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { title: '', text: '' }] })}>+ step</Button>
                        </div>
                    )}
                    {b.type === 'split' && (
                        <div className="space-y-2">
                            <textarea
                                value={b.body || ''}
                                onChange={(e) => update(i, { body: e.target.value })}
                                placeholder="Supporting paragraph"
                                rows={3}
                                className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            <ImageField mediaId={b.media_id} onChange={(media_id) => update(i, { media_id })} />
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={!!b.flip} onChange={(e) => update(i, { flip: e.target.checked })} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                                Image on the left
                            </label>
                        </div>
                    )}
                    {b.type === 'testimonials' && (
                        <div className="space-y-2">
                            {(b.items || []).map((quote, j) => (
                                <div key={j} className="space-y-2 rounded border border-gray-100 p-2">
                                    <textarea
                                        value={quote.quote || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, quote: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Quote"
                                        rows={2}
                                        className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <div className="flex gap-2">
                                        <input
                                            value={quote.name || ''}
                                            onChange={(e) => {
                                                const items = b.items.map((it, idx) => (idx === j ? { ...it, name: e.target.value } : it));
                                                update(i, { items });
                                            }}
                                            placeholder="Name"
                                            className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <input
                                            value={quote.role || ''}
                                            onChange={(e) => {
                                                const items = b.items.map((it, idx) => (idx === j ? { ...it, role: e.target.value } : it));
                                                update(i, { items });
                                            }}
                                            placeholder="Role, company"
                                            className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                    </div>
                                    <ImageField mediaId={quote.media_id} onChange={(media_id) => {
                                        const items = b.items.map((it, idx) => (idx === j ? { ...it, media_id } : it));
                                        update(i, { items });
                                    }} />
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { quote: '', name: '', role: '', media_id: null }] })}>+ testimonial</Button>
                        </div>
                    )}
                    {b.type === 'team' && (
                        <div className="space-y-2">
                            {(b.members || []).map((member, j) => (
                                <div key={j} className="space-y-2 rounded border border-gray-100 p-2">
                                    <div className="flex gap-2">
                                        <input
                                            value={member.name || ''}
                                            onChange={(e) => {
                                                const members = b.members.map((it, idx) => (idx === j ? { ...it, name: e.target.value } : it));
                                                update(i, { members });
                                            }}
                                            placeholder="Name"
                                            className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <input
                                            value={member.role || ''}
                                            onChange={(e) => {
                                                const members = b.members.map((it, idx) => (idx === j ? { ...it, role: e.target.value } : it));
                                                update(i, { members });
                                            }}
                                            placeholder="Role"
                                            className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <button type="button" onClick={() => update(i, { members: b.members.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                    </div>
                                    <ImageField mediaId={member.media_id} onChange={(media_id) => {
                                        const members = b.members.map((it, idx) => (idx === j ? { ...it, media_id } : it));
                                        update(i, { members });
                                    }} />
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { members: [...(b.members || []), { name: '', role: '', media_id: null }] })}>+ member</Button>
                        </div>
                    )}
                    {b.type === 'video' && (
                        <div className="mb-2">
                            {textInput(b.embed_url, (embed_url) => update(i, { embed_url }), 'YouTube or Vimeo URL')}
                            <p className="mt-1 text-xs text-gray-400">Only YouTube/Vimeo embed; anything else renders as a link.</p>
                        </div>
                    )}
                    {b.type === 'pricing' && (
                        <div className="mb-2 grid gap-2 sm:grid-cols-2">
                            <select
                                value={b.product || ''}
                                onChange={(e) => update(i, { product: e.target.value || null })}
                                className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            >
                                <option value="">All active plans</option>
                                <option value="suite">Suite plans</option>
                                <option value="tms">TMS plans</option>
                                <option value="hrms">HRMS plans</option>
                            </select>
                            <p className="self-center text-xs text-gray-400">Cards, prices, and features pull live from subscription plans.</p>
                        </div>
                    )}
                    {b.type === 'faq' && (
                        <div className="space-y-2">
                            {(b.items || []).map((faq, j) => (
                                <div key={j} className="space-y-2 rounded border border-gray-100 p-2">
                                    <input
                                        value={faq.q || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, q: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Question"
                                        className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm font-medium"
                                    />
                                    <textarea
                                        value={faq.a || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, a: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder="Answer (also feeds Google's FAQ rich result)"
                                        rows={2}
                                        className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <div className="text-right">
                                        <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-xs text-red-400">remove</button>
                                    </div>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { q: '', a: '' }] })}>+ question</Button>
                        </div>
                    )}
                    {b.type === 'contact_form' && (
                        <div className="mb-2 grid gap-2 sm:grid-cols-2">
                            <select
                                value={b.source || 'contact'}
                                onChange={(e) => update(i, { source: e.target.value })}
                                className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            >
                                <option value="contact">Contact request</option>
                                <option value="demo">Demo request</option>
                            </select>
                            <p className="self-center text-xs text-gray-400">Submissions land in Platform → Leads.</p>
                        </div>
                    )}
                    {b.type === 'comparison' && (
                        <div className="space-y-2">
                            <div className="flex gap-2">
                                {(b.columns || []).map((col, j) => (
                                    <div key={j} className="flex flex-1 gap-1">
                                        <input
                                            value={col.heading || ''}
                                            onChange={(e) => {
                                                const columns = b.columns.map((c, idx) => (idx === j ? { ...c, heading: e.target.value } : c));
                                                update(i, { columns });
                                            }}
                                            placeholder={`Column ${j + 1}`}
                                            className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <button type="button" onClick={() => update(i, { columns: b.columns.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                    </div>
                                ))}
                                <Button type="button" variant="ghost" onClick={() => update(i, { columns: [...(b.columns || []), { heading: '' }] })}>+ column</Button>
                            </div>
                            {(b.rows || []).map((row, j) => (
                                <div key={j} className="flex gap-2">
                                    <input
                                        value={row.label || ''}
                                        onChange={(e) => {
                                            const rows = b.rows.map((r, idx) => (idx === j ? { ...r, label: e.target.value } : r));
                                            update(i, { rows });
                                        }}
                                        placeholder="Row label"
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    {(b.columns || []).map((col, k) => (
                                        <input
                                            key={k}
                                            value={row.values?.[k] || ''}
                                            onChange={(e) => {
                                                const values = [...(row.values || [])];
                                                values[k] = e.target.value;
                                                const rows = b.rows.map((r, idx) => (idx === j ? { ...r, values } : r));
                                                update(i, { rows });
                                            }}
                                            placeholder="yes / no / text"
                                            className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                    ))}
                                    <button type="button" onClick={() => update(i, { rows: b.rows.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { rows: [...(b.rows || []), { label: '', values: [] }] })}>+ row</Button>
                        </div>
                    )}
                    {b.cta && (
                        <div className="flex gap-2">
                            <input
                                value={b.cta?.label || ''}
                                onChange={(e) => update(i, { cta: { ...b.cta, label: e.target.value } })}
                                placeholder="Call to action label"
                                className="w-1/2 rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            <input
                                value={b.cta?.href || ''}
                                onChange={(e) => update(i, { cta: { ...b.cta, href: e.target.value } })}
                                placeholder="Link (e.g. /app/register)"
                                className="w-1/2 rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
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
                {BLOCK_TYPES.map((t) => (
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
                        <input type="checkbox" checked={!!form.sitemap_include} onChange={(e) => set('sitemap_include', e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                        Include in sitemap
                    </label>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input label="SEO title" name="seo_title" value={form.seo_title || ''} onChange={(e) => set('seo_title', e.target.value)} />
                    <Input label="OG image URL" name="og_image" value={form.og_image || ''} onChange={(e) => set('og_image', e.target.value)} />
                </div>
                <Input label="Meta description" name="meta_description" value={form.meta_description || ''} onChange={(e) => set('meta_description', e.target.value)} error={errors.meta_description?.[0]} />
                <div className="rounded-lg bg-gray-50 p-3">
                    <p className="mb-3 text-sm font-semibold text-gray-700">SEO</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label="OG title (defaults to SEO title)" name="og_title" value={form.og_title || ''} onChange={(e) => set('og_title', e.target.value)} />
                        <Input label="Canonical URL (defaults to this page)" name="canonical_url" value={form.canonical_url || ''} onChange={(e) => set('canonical_url', e.target.value)} error={errors.canonical_url?.[0]} />
                    </div>
                    <div className="mt-4">
                        <Input label="OG description (defaults to meta description)" name="og_description" value={form.og_description || ''} onChange={(e) => set('og_description', e.target.value)} />
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" checked={!!form.noindex} onChange={(e) => set('noindex', e.target.checked)} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                        Hide from search engines (noindex, excluded from sitemap)
                    </label>
                </div>
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
            toast.error('Could not load the page.');
        }
    }

    async function openPreview(page) {
        try {
            const { data } = await api.get(`/system/pages/${page.id}`);
            window.open(data.page.preview_url, '_blank', 'noopener');
        } catch {
            toast.error('Could not build the preview link.');
        }
    }

    async function duplicate(page) {
        try {
            const { data } = await api.post(`/system/pages/${page.id}/duplicate`);
            toast.success(`Duplicated as ${data.page.slug}.`);
            fetchPages();
        } catch (e) {
            toast.error(fieldErrors(e).form || 'Could not duplicate the page.');
        }
    }

    async function save(form) {
        const payload = { ...form };
        if (form.id) {
            await api.put(`/system/pages/${form.id}`, payload);
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
        if (!(await confirm({ title: 'Delete page?', message: `Delete "${page.title}"? This cannot be undone.`, variant: 'danger' }))) return;
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
                                            {page.slug === 'home' ? '/' : `/${page.slug}`}
                                        </span>
                                    </td>
                                    <td className="py-2.5">
                                        <div className="flex justify-end gap-2">
                                            <a href={page.slug === 'home' ? '/' : `/${page.slug}`} target="_blank" rel="noreferrer" className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">view</a>
                                            <button type="button" onClick={() => openPreview(page)} className="text-[var(--accent-soft-text)] hover:text-[var(--accent)]">preview</button>
                                            <button type="button" onClick={() => openEditor(page)} className="text-gray-500 hover:text-gray-700">edit</button>
                                            <button type="button" onClick={() => duplicate(page)} className="text-gray-500 hover:text-gray-700">duplicate</button>
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