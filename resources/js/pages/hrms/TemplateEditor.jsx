import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';

const CATEGORIES = [
    { value: 'document', label: 'Document' },
    { value: 'task', label: 'Task' },
    { value: 'asset', label: 'Asset' },
    { value: 'access', label: 'System access' },
    { value: 'orientation', label: 'Orientation' },
    { value: 'other', label: 'Other' },
];

const SCOPES = [
    { value: 'hr', label: 'HR' },
    { value: 'manager', label: 'Manager' },
    { value: 'employee', label: 'Employee' },
    { value: 'it', label: 'IT' },
];

const blankItem = () => ({
    id: null,
    title: '',
    description: '',
    category: 'task',
    owner_scope: 'hr',
    due_offset_days: 0,
    is_mandatory: false,
});

/**
 * The onboarding template editor: scalars plus the checklist items, saved
 * together.
 *
 * Items persist through their own endpoints (add/update/remove/reorder),
 * because a template edit must never rewrite a running case — but the editor
 * presents one Save that syncs the whole draft in order: scalars first, then
 * new and changed items, then deletions, then the final order. A reorder
 * that ran before the new items existed would position rows that are about
 * to move again.
 */
export default function TemplateEditor({ templateId, onSaved }) {
    const toast = useToast();

    const [template, setTemplate] = useState(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [isActive, setIsActive] = useState(true);
    const [items, setItems] = useState([]);
    const [removedIds, setRemovedIds] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        setLoading(true);

        api.get(`/hrms/onboarding/templates/${templateId}`)
            .then(({ data }) => {
                const record = data.template;

                setTemplate(record);
                setName(record.name ?? '');
                setDescription(record.description ?? '');
                setIsActive(record.is_active ?? true);
                setItems(
                    (record.tasks ?? []).map((task) => ({
                        id: task.id,
                        title: task.title ?? '',
                        description: task.description ?? '',
                        category: task.category ?? 'task',
                        owner_scope: task.owner_scope ?? 'hr',
                        due_offset_days: task.due_offset_days ?? 0,
                        is_mandatory: task.is_mandatory ?? false,
                    })),
                );
                setRemovedIds([]);
                setErrors({});
            })
            .catch(() => setTemplate(null))
            .finally(() => setLoading(false));
    }, [templateId]);

    function updateItem(index, patch) {
        setItems((list) => list.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    }

    function removeItem(index) {
        setItems((list) => {
            const victim = list[index];

            if (victim?.id) setRemovedIds((ids) => [...ids, victim.id]);

            return list.filter((_, i) => i !== index);
        });
    }

    function moveItem(index, dir) {
        setItems((list) => {
            const next = [...list];
            const j = index + dir;

            if (j < 0 || j >= next.length) return list;

            [next[index], next[j]] = [next[j], next[index]];

            return next;
        });
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        try {
            await api.put(`/hrms/onboarding/templates/${templateId}`, {
                name,
                description: description || null,
                is_active: isActive,
            });

            const idByIndex = [];

            for (let i = 0; i < items.length; i += 1) {
                const item = items[i];
                const payload = {
                    title: item.title,
                    description: item.description || null,
                    category: item.category,
                    owner_scope: item.owner_scope,
                    due_offset_days: Number(item.due_offset_days) || 0,
                    is_mandatory: item.is_mandatory,
                };

                if (item.id) {
                    // eslint-disable-next-line no-await-in-loop
                    await api.put(`/hrms/onboarding/templates/${templateId}/tasks/${item.id}`, payload);
                    idByIndex.push(item.id);
                } else {
                    // eslint-disable-next-line no-await-in-loop
                    const { data } = await api.post(`/hrms/onboarding/templates/${templateId}/tasks`, payload);

                    idByIndex.push(data.task.id);
                }
            }

            for (const id of removedIds) {
                // eslint-disable-next-line no-await-in-loop
                await api.delete(`/hrms/onboarding/templates/${templateId}/tasks/${id}`);
            }

            if (idByIndex.length > 0) {
                await api.post(`/hrms/onboarding/templates/${templateId}/tasks/reorder`, {
                    ordered_ids: idByIndex,
                });
            }

            toast.success('Template saved.');
            onSaved?.();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <div className="flex justify-center py-10">
                <Spinner />
            </div>
        );
    }

    if (!template) {
        return <Alert>Unable to load this template.</Alert>;
    }

    return (
        <form onSubmit={save} className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} />
                <label className="flex items-end gap-2 pb-2 text-sm text-gray-700">
                    <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
                    Active — available when starting a case
                </label>
            </div>
            <Input label="Description" value={description} onChange={(e) => setDescription(e.target.value)} />
            {errors.name && <Alert>{errors.name}</Alert>}
            {errors.form && <Alert>{errors.form}</Alert>}

            <div>
                <p className="mb-1.5 text-sm font-medium text-gray-700">Checklist items</p>
                <div className="space-y-3">
                    {items.map((item, i) => (
                        <div key={item.id ?? `new-${i}`} className="rounded-lg border border-gray-200 p-3">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="font-mono text-xs font-semibold uppercase text-indigo-600">
                                    {item.category}
                                    {item.is_mandatory ? ' · mandatory' : ''}
                                </span>
                                <span className="flex gap-1">
                                    <button type="button" onClick={() => moveItem(i, -1)} className="rounded px-1.5 text-gray-400 hover:bg-gray-100">↑</button>
                                    <button type="button" onClick={() => moveItem(i, 1)} className="rounded px-1.5 text-gray-400 hover:bg-gray-100">↓</button>
                                    <button type="button" onClick={() => removeItem(i)} className="rounded px-1.5 text-red-400 hover:bg-red-50">✕</button>
                                </span>
                            </div>
                            <Input label="Title" value={item.title} onChange={(e) => updateItem(i, { title: e.target.value })} />
                            <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                <Select label="Category" value={item.category} onChange={(e) => updateItem(i, { category: e.target.value })}>
                                    {CATEGORIES.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </Select>
                                <Select label="Owner" value={item.owner_scope} onChange={(e) => updateItem(i, { owner_scope: e.target.value })}>
                                    {SCOPES.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </Select>
                                <Input
                                    label="Due offset (days)"
                                    type="number"
                                    value={item.due_offset_days}
                                    onChange={(e) => updateItem(i, { due_offset_days: e.target.value })}
                                />
                                <label className="flex items-end gap-2 pb-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={item.is_mandatory}
                                        onChange={(e) => updateItem(i, { is_mandatory: e.target.checked })}
                                    />
                                    Mandatory
                                </label>
                            </div>
                        </div>
                    ))}
                </div>
                <Button type="button" variant="secondary" size="sm" className="mt-2" onClick={() => setItems((list) => [...list, blankItem()])}>
                    Add item
                </Button>
            </div>

            {errors.title && <Alert>{errors.title}</Alert>}

            <Button type="submit" loading={saving}>
                Save template
            </Button>
        </form>
    );
}
