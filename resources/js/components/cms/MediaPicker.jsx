import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import ContentSkeleton from '../ui/ContentSkeleton';
import Pagination from '../ui/Pagination';
import { useToast } from '../../context/ToastContext';
import { usePaginated } from '../../hooks/useResource';

function fetchMedia(page, q, signal) {
    return api
        .get('/system/media', { signal, params: { page, q: q || undefined, per_page: 12 } })
        .then(({ data }) => data);
}

function MediaBrowser({ value, onPick }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [q, setQ] = useState('');
    const [file, setFile] = useState(null);
    const [alt, setAlt] = useState('');
    const [uploading, setUploading] = useState(false);
    const [errors, setErrors] = useState({});
    const { data, loading, reload, page, setPage } = usePaginated(
        (p, signal) => fetchMedia(p, q, signal),
        [q],
    );
    const items = data?.media || [];
    const pagination = data?.pagination;

    async function upload(e) {
        e.preventDefault();
        if (!file) return;
        setUploading(true);
        setErrors({});
        try {
            const form = new FormData();
            form.append('file', file);
            if (alt) form.append('alt', alt);
            const { data: created } = await api.post('/system/media', form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            toast.success(t('admin.mediaLibrary.uploaded'));
            setFile(null);
            setAlt('');
            setPage(1);
            reload();
            onPick(created.media);
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setUploading(false);
        }
    }

    return (
        <div className="space-y-4 px-6 pb-6 pt-4">
            <form onSubmit={upload} className="flex flex-col gap-2 rounded-lg bg-gray-50 p-3 sm:flex-row sm:items-end">
                <div className="flex-1">
                    <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" onChange={(e) => setFile(e.target.files[0] || null)} className="w-full text-sm text-gray-600" />
                    {errors.file?.[0] && <p className="mt-1 text-xs text-red-600">{errors.file[0]}</p>}
                </div>
                <div className="flex-1">
                    <Input label={t('admin.mediaLibrary.altText')} name="alt" value={alt} onChange={(e) => setAlt(e.target.value)} placeholder={t('admin.mediaLibrary.altPlaceholder')} />
                </div>
                <Button type="submit" disabled={uploading || !file}>{uploading ? t('admin.mediaLibrary.uploading') : t('admin.mediaLibrary.upload')}</Button>
            </form>

            <Input label={t('admin.mediaPicker.searchLibrary')} name="q" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder={t('admin.mediaLibrary.searchPlaceholder')} />

            {loading ? (
                <ContentSkeleton size="sm" />
            ) : items.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-400">{t('admin.mediaPicker.empty')}</p>
            ) : (
                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    {items.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => onPick(item)}
                            className={`group overflow-hidden rounded-lg border-2 transition ${value?.id === item.id ? 'border-[var(--accent)]' : 'border-transparent hover:border-[var(--accent-ring)]'}`}
                            title={item.original_name}
                        >
                            <img src={item.url} alt={item.alt || item.original_name} className="aspect-[4/3] w-full object-cover" loading="lazy" />
                        </button>
                    ))}
                </div>
            )}
            {pagination && (
                <Pagination page={page} pages={pagination.last_page} total={pagination.total} onChange={setPage} />
            )}
        </div>
    );
}

/**
 * Reusable media-library picker for CMS editors. Shows the current selection
 * (thumbnail or placeholder) and opens a searchable browser with an upload
 * form; `onSelect(media|null)` receives the chosen row (blocks store the id).
 * The browser mounts only while open, so no request fires until it is needed.
 */
export default function MediaPicker({ value, onSelect, label }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    function pick(item) {
        onSelect(item);
        setOpen(false);
    }

    return (
        <div>
            <p className="mb-1.5 text-sm font-medium text-gray-700">{label ?? t('admin.mediaPicker.image')}</p>
            <div className="flex items-center gap-3">
                {value ? (
                    <img src={value.url} alt={value.alt || ''} className="h-16 w-24 rounded-lg border border-gray-200 object-cover" />
                ) : (
                    <span className="flex h-16 w-24 items-center justify-center rounded-lg border border-dashed border-gray-300 text-xs text-gray-400">{t('admin.mediaPicker.noImage')}</span>
                )}
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" onClick={() => setOpen(true)}>{t('admin.mediaPicker.choose')}</Button>
                    {value && (
                        <Button type="button" variant="ghost" onClick={() => onSelect(null)}>{t('admin.mediaPicker.clear')}</Button>
                    )}
                </div>
            </div>

            <Modal open={open} title={t('admin.mediaPicker.chooseImage')} onClose={() => setOpen(false)} size="lg">
                {open && <MediaBrowser value={value} onPick={pick} />}
            </Modal>
        </div>
    );
}
