import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Button from '../ui/Button';
import RichTextEditor from '../ui/RichTextEditor';
import MediaPicker from '../cms/MediaPicker';

const BLOCK_TYPES = ['hero', 'logo_cloud', 'features', 'showcase', 'split', 'steps', 'stats', 'testimonials', 'team', 'video', 'pricing', 'faq', 'comparison', 'contact_form', 'text', 'richtext', 'cta'];

// Media id ↔ picker object bridge: blocks store the id, the picker needs the
// row. Hydrated once per id; a deleted image resolves to null (unpicked).
function ImageField({ mediaId, onChange }) {
    const { t } = useTranslation();
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

    return <MediaPicker label={t('admin.cmsPages.image')} value={media} onSelect={(m) => onChange(m?.id ?? null)} />;
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

export default function BlockEditor({ blocks, onChange }) {
    const { t } = useTranslation();

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
                        placeholder={t('admin.cmsPages.headingPlaceholder')}
                        className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                    />
                    {b.type === 'hero' && (
                        <input
                            value={b.subtext || ''}
                            onChange={(e) => update(i, { subtext: e.target.value })}
                            placeholder={t('admin.cmsPages.subtextPlaceholder')}
                            className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                        />
                    )}
                    {b.type === 'hero' && <ImageField mediaId={b.media_id} onChange={(media_id) => update(i, { media_id })} />}
                    {b.type === 'showcase' && (
                        <div className="space-y-2">
                            {(b.items || []).map((item, j) => (
                                <div key={j} className="space-y-2 rounded border border-gray-100 p-2">
                                    <div className="flex gap-2">
                                        {textInput(item.title, (v) => update(i, { items: b.items.map((it, idx) => (idx === j ? { ...it, title: v } : it)) }), t('admin.cmsPages.itemTitlePlaceholder'))}
                                        <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                    </div>
                                    {textInput(item.text, (v) => update(i, { items: b.items.map((it, idx) => (idx === j ? { ...it, text: v } : it)) }), t('admin.cmsPages.itemTextPlaceholder'))}
                                    <ImageField mediaId={item.media_id} onChange={(media_id) => update(i, { items: b.items.map((it, idx) => (idx === j ? { ...it, media_id } : it)) })} />
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { title: '', text: '', media_id: null }] })}>{t('admin.cmsPages.addScreenshot')}</Button>
                        </div>
                    )}
                    {b.type === 'text' && (
                        <textarea
                            value={b.body || ''}
                            onChange={(e) => update(i, { body: e.target.value })}
                            placeholder={t('admin.cmsPages.bodyPlaceholder')}
                            rows={3}
                            className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                        />
                    )}
                    {b.type === 'richtext' && (
                        <RichTextEditor
                            value={b.html || ''}
                            onChange={(html) => update(i, { html })}
                            placeholder={t('admin.cmsPages.richtextPlaceholder')}
                        />
                    )}
                    {b.type === 'cta' && (
                        <input
                            value={b.text || ''}
                            onChange={(e) => update(i, { text: e.target.value })}
                            placeholder={t('admin.cmsPages.supportingTextPlaceholder')}
                            className="mb-2 w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                        />
                    )}
                    {['steps', 'split', 'testimonials', 'team', 'pricing', 'faq', 'contact_form', 'showcase'].includes(b.type) && (
                        <div className="mb-2 grid gap-2 sm:grid-cols-2">
                            <input
                                value={b.eyebrow || ''}
                                onChange={(e) => update(i, { eyebrow: e.target.value })}
                                placeholder={t('admin.cmsPages.eyebrowPlaceholder')}
                                className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            {b.type !== 'split' && (
                                <input
                                    value={b.lead || ''}
                                    onChange={(e) => update(i, { lead: e.target.value })}
                                    placeholder={t('admin.cmsPages.leadPlaceholder')}
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
                                        placeholder={t('admin.cmsPages.companyNamePlaceholder')}
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { logos: b.logos.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { logos: [...(b.logos || []), { name: '', media_id: null }] })}>{t('admin.cmsPages.addLogo')}</Button>
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
                                        placeholder={t('admin.cmsPages.statValuePlaceholder')}
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <input
                                        value={stat.label || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, label: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder={t('admin.cmsPages.labelPlaceholder')}
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { value: '', label: '' }] })}>{t('admin.cmsPages.addStat')}</Button>
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
                                        placeholder={t('admin.cmsPages.stepTitlePlaceholder')}
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <input
                                        value={step.text || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, text: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder={t('admin.cmsPages.whatHappensPlaceholder')}
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { title: '', text: '' }] })}>{t('admin.cmsPages.addStep')}</Button>
                        </div>
                    )}
                    {b.type === 'split' && (
                        <div className="space-y-2">
                            <textarea
                                value={b.body || ''}
                                onChange={(e) => update(i, { body: e.target.value })}
                                placeholder={t('admin.cmsPages.supportingParagraphPlaceholder')}
                                rows={3}
                                className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            <ImageField mediaId={b.media_id} onChange={(media_id) => update(i, { media_id })} />
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={!!b.flip} onChange={(e) => update(i, { flip: e.target.checked })} className="h-4 w-4 rounded border-gray-300 accent-[var(--accent)]" />
                                {t('admin.cmsPages.imageOnLeft')}
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
                                        placeholder={t('admin.cmsPages.quotePlaceholder')}
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
                                            placeholder={t('admin.common.name')}
                                            className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <input
                                            value={quote.role || ''}
                                            onChange={(e) => {
                                                const items = b.items.map((it, idx) => (idx === j ? { ...it, role: e.target.value } : it));
                                                update(i, { items });
                                            }}
                                            placeholder={t('admin.cmsPages.roleCompanyPlaceholder')}
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
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { quote: '', name: '', role: '', media_id: null }] })}>{t('admin.cmsPages.addTestimonial')}</Button>
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
                                            placeholder={t('admin.common.name')}
                                            className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <input
                                            value={member.role || ''}
                                            onChange={(e) => {
                                                const members = b.members.map((it, idx) => (idx === j ? { ...it, role: e.target.value } : it));
                                                update(i, { members });
                                            }}
                                            placeholder={t('admin.cmsPages.rolePlaceholder')}
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
                            <Button type="button" variant="ghost" onClick={() => update(i, { members: [...(b.members || []), { name: '', role: '', media_id: null }] })}>{t('admin.cmsPages.addMember')}</Button>
                        </div>
                    )}
                    {b.type === 'video' && (
                        <div className="mb-2">
                            {textInput(b.embed_url, (embed_url) => update(i, { embed_url }), t('admin.cmsPages.embedUrlPlaceholder'))}
                            <p className="mt-1 text-xs text-gray-400">{t('admin.cmsPages.embedNote')}</p>
                        </div>
                    )}
                    {b.type === 'pricing' && (
                        <div className="mb-2 grid gap-2 sm:grid-cols-2">
                            <select
                                value={b.product || ''}
                                onChange={(e) => update(i, { product: e.target.value || null })}
                                className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            >
                                <option value="">{t('admin.cmsPages.allActivePlans')}</option>
                                <option value="suite">{t('admin.cmsPages.suitePlans')}</option>
                                <option value="tms">{t('admin.cmsPages.tmsPlans')}</option>
                                <option value="hrms">{t('admin.cmsPages.hrmsPlans')}</option>
                            </select>
                            <p className="self-center text-xs text-gray-400">{t('admin.cmsPages.pricingNote')}</p>
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
                                        placeholder={t('admin.cmsPages.questionPlaceholder')}
                                        className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm font-medium"
                                    />
                                    <textarea
                                        value={faq.a || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, a: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder={t('admin.cmsPages.answerPlaceholder')}
                                        rows={2}
                                        className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <div className="text-right">
                                        <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-xs text-red-400">{t('admin.cmsPages.remove')}</button>
                                    </div>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...(b.items || []), { q: '', a: '' }] })}>{t('admin.cmsPages.addQuestion')}</Button>
                        </div>
                    )}
                    {b.type === 'contact_form' && (
                        <div className="mb-2 grid gap-2 sm:grid-cols-2">
                            <select
                                value={b.source || 'contact'}
                                onChange={(e) => update(i, { source: e.target.value })}
                                className="rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            >
                                <option value="contact">{t('admin.cmsPages.contactRequest')}</option>
                                <option value="demo">{t('admin.cmsPages.demoRequest')}</option>
                            </select>
                            <p className="self-center text-xs text-gray-400">{t('admin.cmsPages.contactFormNote')}</p>
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
                                            placeholder={t('admin.cmsPages.columnLabel', { n: j + 1 })}
                                            className="w-full rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                        <button type="button" onClick={() => update(i, { columns: b.columns.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                    </div>
                                ))}
                                <Button type="button" variant="ghost" onClick={() => update(i, { columns: [...(b.columns || []), { heading: '' }] })}>{t('admin.cmsPages.addColumn')}</Button>
                            </div>
                            {(b.rows || []).map((row, j) => (
                                <div key={j} className="flex gap-2">
                                    <input
                                        value={row.label || ''}
                                        onChange={(e) => {
                                            const rows = b.rows.map((r, idx) => (idx === j ? { ...r, label: e.target.value } : r));
                                            update(i, { rows });
                                        }}
                                        placeholder={t('admin.cmsPages.rowLabelPlaceholder')}
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
                                            placeholder={t('admin.cmsPages.yesNoTextPlaceholder')}
                                            className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                        />
                                    ))}
                                    <button type="button" onClick={() => update(i, { rows: b.rows.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { rows: [...(b.rows || []), { label: '', values: [] }] })}>{t('admin.cmsPages.addRow')}</Button>
                        </div>
                    )}
                    {b.cta && (
                        <div className="flex gap-2">
                            <input
                                value={b.cta?.label || ''}
                                onChange={(e) => update(i, { cta: { ...b.cta, label: e.target.value } })}
                                placeholder={t('admin.cmsPages.ctaLabelPlaceholder')}
                                className="w-1/2 rounded border border-gray-200 px-2 py-1.5 text-sm outline-none focus:border-[var(--accent)]"
                            />
                            <input
                                value={b.cta?.href || ''}
                                onChange={(e) => update(i, { cta: { ...b.cta, href: e.target.value } })}
                                placeholder={t('admin.cmsPages.ctaLinkPlaceholder')}
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
                                        placeholder={t('admin.cmsPages.featureTitlePlaceholder')}
                                        className="w-1/3 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <input
                                        value={item.text || ''}
                                        onChange={(e) => {
                                            const items = b.items.map((it, idx) => (idx === j ? { ...it, text: e.target.value } : it));
                                            update(i, { items });
                                        }}
                                        placeholder={t('admin.cmsPages.descriptionPlaceholder')}
                                        className="flex-1 rounded border border-gray-200 px-2 py-1.5 text-sm"
                                    />
                                    <button type="button" onClick={() => update(i, { items: b.items.filter((_, idx) => idx !== j) })} className="text-red-400">✕</button>
                                </div>
                            ))}
                            <Button type="button" variant="ghost" onClick={() => update(i, { items: [...b.items, { title: '', text: '' }] })}>{t('admin.cmsPages.addFeature')}</Button>
                        </div>
                    )}
                </div>
            ))}
            <div className="flex flex-wrap gap-2">
                {BLOCK_TYPES.map((type) => (
                    <Button key={type} type="button" variant="ghost" onClick={() => add(type)}>+ {type}</Button>
                ))}
            </div>
        </div>
    );
}
