import { useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Spinner from '../components/ui/Spinner';
import Pagination from '../components/ui/Pagination';
import { useToast } from '../context/ToastContext';
import { useConfirm } from '../components/ui/ConfirmDialog';
import usePageTitle from '../hooks/usePageTitle';
import { usePaginated } from '../hooks/useResource';
import { useSetCrumbs } from '../context/BreadcrumbContext';

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function MediaLibrary() {
    usePageTitle('Media Library');
    useSetCrumbs([{ label: 'Platform', to: '/admin' }, { label: 'Media' }]);
    const toast = useToast();
    const confirm = useConfirm();
    const [q, setQ] = useState('');
    const [file, setFile] = useState(null);
    const [alt, setAlt] = useState('');
    const [uploading, setUploading] = useState(false);
    const [errors, setErrors] = useState({});
    const [editingAlt, setEditingAlt] = useState({});
    const { data, loading, reload, page, setPage } = usePaginated(
        (p, signal) => api.get('/system/media', { signal, params: { page: p, q: q || undefined, per_page: 24 } }).then(({ data }) => data),
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
            await api.post('/system/media', form, { headers: { 'Content-Type': 'multipart/form-data' } });
            toast.success('Image uploaded.');
            setFile(null);
            setAlt('');
            setPage(1);
            reload();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setUploading(false);
        }
    }

    async function saveAlt(item) {
        try {
            await api.put(`/system/media/${item.id}`, { alt: editingAlt[item.id] ?? '' });
            toast.success('Alt text saved.');
            reload();
        } catch {
            toast.error('Could not save alt text.');
        }
    }

    async function remove(item) {
        if (!(await confirm({ title: 'Delete image?', message: `Delete "${item.original_name}"? Pages using it will show a broken image.`, variant: 'danger' }))) return;
        try {
            await api.delete(`/system/media/${item.id}`);
            toast.success('Image deleted.');
            reload();
        } catch {
            toast.error('Could not delete the image.');
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-800">Media Library</h1>
                    <p className="text-sm text-gray-500">Images for the public marketing site. Alt text feeds SEO.</p>
                </div>
            </div>

            <Card>
                <form onSubmit={upload} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Upload image (JPEG/PNG/GIF/WebP, max 5 MB)</label>
                        <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" onChange={(e) => setFile(e.target.files[0] || null)} className="w-full text-sm text-gray-600" />
                        {errors.file?.[0] && <p className="mt-1 text-xs text-red-600">{errors.file[0]}</p>}
                    </div>
                    <div className="flex-1">
                        <Input label="Alt text" name="alt" value={alt} onChange={(e) => setAlt(e.target.value)} placeholder="Describe the image" />
                    </div>
                    <Button type="submit" disabled={uploading || !file}>{uploading ? 'Uploading…' : 'Upload'}</Button>
                </form>
            </Card>

            <Card>
                <div className="mb-4 max-w-sm">
                    <Input label="Search" name="q" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} placeholder="File name or alt text" />
                </div>
                {loading ? (
                    <Spinner />
                ) : items.length === 0 ? (
                    <p className="py-6 text-center text-sm text-gray-400">No images yet.</p>
                ) : (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {items.map((item) => (
                            <div key={item.id} className="overflow-hidden rounded-xl border border-gray-200">
                                <img src={item.url} alt={item.alt || item.original_name} className="aspect-[4/3] w-full object-cover" loading="lazy" />
                                <div className="space-y-2 p-3">
                                    <p className="truncate text-xs font-medium text-gray-700" title={item.original_name}>{item.original_name}</p>
                                    <p className="text-[11px] text-gray-400">
                                        {[item.width && item.height ? `${item.width}×${item.height}` : null, formatSize(item.size)].filter(Boolean).join(' · ')}
                                    </p>
                                    <Input
                                        label="Alt text"
                                        name={`alt-${item.id}`}
                                        value={editingAlt[item.id] ?? item.alt ?? ''}
                                        onChange={(e) => setEditingAlt((s) => ({ ...s, [item.id]: e.target.value }))}
                                        placeholder="Describe the image"
                                    />
                                    <div className="flex justify-end gap-3 text-xs">
                                        <button type="button" onClick={() => saveAlt(item)} className="font-medium text-[var(--accent-soft-text)] hover:text-[var(--accent)]">save alt</button>
                                        <button type="button" onClick={() => remove(item)} className="font-medium text-red-500 hover:text-red-400">delete</button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
                {pagination && (
                    <div className="mt-4">
                        <Pagination page={page} pages={pagination.last_page} total={pagination.total} onChange={setPage} />
                    </div>
                )}
            </Card>
        </div>
    );
}
