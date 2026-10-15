import { useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import Spinner from '../ui/Spinner';
import Pagination from '../ui/Pagination';
import { useToast } from '../../context/ToastContext';
import { usePaginated } from '../../hooks/useResource';

function fetchMedia(page, q, signal) {
    return api
        .get('/system/media', { signal, params: { page, q: q || undefined, per_page: 12 } })
        .then(({ data }) => data);
}

function MediaBrowser({ value, onPick }) {
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
            toast.success('Image uploaded.');
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
                    <Input label="Alt text" name="alt" value={alt} onChange={(e) => setAlt(e.target.value)} placeholder="Describe the image" />
                </div>
                <Button type="submit" disabled={uploading || !file}>{uploading ? 'Uploading…' : 'Upload'}</Button>
            </form>

            <Input label="Search library" name="q" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder="File name or alt text" />

            {loading ? (
                <Spinner />
            ) : items.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-400">No images yet — upload one above.</p>
            ) : (
                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    {items.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => onPick(item)}
                            className={`group overflow-hidden rounded-lg border-2 transition ${value?.id === item.id ? 'border-indigo-500' : 'border-transparent hover:border-indigo-300'}`}
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
export default function MediaPicker({ value, onSelect, label = 'Image' }) {
    const [open, setOpen] = useState(false);

    function pick(item) {
        onSelect(item);
        setOpen(false);
    }

    return (
        <div>
            <p className="mb-1.5 text-sm font-medium text-gray-700">{label}</p>
            <div className="flex items-center gap-3">
                {value ? (
                    <img src={value.url} alt={value.alt || ''} className="h-16 w-24 rounded-lg border border-gray-200 object-cover" />
                ) : (
                    <span className="flex h-16 w-24 items-center justify-center rounded-lg border border-dashed border-gray-300 text-xs text-gray-400">No image</span>
                )}
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" onClick={() => setOpen(true)}>Choose…</Button>
                    {value && (
                        <Button type="button" variant="ghost" onClick={() => onSelect(null)}>Clear</Button>
                    )}
                </div>
            </div>

            <Modal open={open} title="Choose image" onClose={() => setOpen(false)} size="lg">
                {open && <MediaBrowser value={value} onPick={pick} />}
            </Modal>
        </div>
    );
}
