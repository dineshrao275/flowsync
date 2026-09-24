import { useState } from 'react';
import { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import Modal from '../ui/Modal';
import Alert from '../ui/Alert';
import { fieldClass } from '../ui/fieldStyles';

export default function CreateTaskModal({ options, topLevelTasks, projectKey, saving, error, onClose, onCreate }) {
    const [form, setForm] = useState({
        title: '',
        description: '',
        status_id: options.statuses.find((s) => s.is_default)?.id ?? options.statuses[0]?.id ?? '',
        priority_id: options.priorities.find((p) => p.is_default)?.id ?? '',
        assignee_id: '',
        parent_id: '',
        labels: [],
        due_date: '',
        estimate_minutes: '',
    });
    const [errors, setErrors] = useState({});

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function toggleLabel(id) {
        setForm((f) => ({
            ...f,
            labels: f.labels.includes(id) ? f.labels.filter((l) => l !== id) : [...f.labels, id],
        }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});
        onCreate({
            ...form,
            assignee_id: form.assignee_id || null,
            parent_id: form.parent_id || null,
            due_date: form.due_date || null,
            estimate_minutes: form.estimate_minutes === '' ? null : Number(form.estimate_minutes),
            labels: form.labels,
        }).catch((err) => setErrors(fieldErrors(err)));
    }

    return (
        <Modal open title="New task" subtitle={`in ${projectKey}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4 px-6 py-5">
                {errors.form && <Alert>{errors.form}</Alert>}
                <Input
                    label="Title"
                    name="task-title"
                    placeholder="What needs doing?"
                    value={form.title}
                    onChange={(e) => set('title', e.target.value)}
                    error={errors.title}
                    required
                    autoFocus
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Select
                        label="Status"
                        value={form.status_id}
                        onChange={(e) => set('status_id', e.target.value)}
                        error={errors.status_id}
                    >
                        {options.statuses.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label="Priority"
                        value={form.priority_id}
                        onChange={(e) => set('priority_id', e.target.value)}
                        error={errors.priority_id}
                    >
                        <option value="">None</option>
                        {options.priorities.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Select
                        label="Assignee"
                        value={form.assignee_id}
                        onChange={(e) => set('assignee_id', e.target.value)}
                        error={errors.assignee_id}
                    >
                        <option value="">Unassigned</option>
                        {options.assignees.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label="Parent task"
                        value={form.parent_id}
                        onChange={(e) => set('parent_id', e.target.value)}
                        error={errors.parent_id}
                    >
                        <option value="">None</option>
                        {topLevelTasks.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.key} · {t.title}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Description</label>
                    <textarea
                        className={`${fieldClass} min-h-20`}
                        rows="3"
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                        placeholder="Context, acceptance criteria…"
                    />
                </div>
                {options.labels?.length > 0 && (
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">Labels</label>
                        <div className="flex flex-wrap gap-2">
                            {options.labels.map((label) => (
                                <button
                                    key={label.id}
                                    type="button"
                                    onClick={() => toggleLabel(label.id)}
                                    className={`rounded-full px-3 py-1 text-xs font-medium transition ${
                                        form.labels.includes(label.id)
                                            ? 'bg-indigo-600 text-white'
                                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    }`}
                                >
                                    {label.name}
                                </button>
                            ))}
                        </div>
                        {errors.labels && <p className="mt-1.5 text-sm text-red-600">{errors.labels}</p>}
                    </div>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label="Due date"
                        type="date"
                        value={form.due_date}
                        onChange={(e) => set('due_date', e.target.value)}
                    />
                    <Input
                        label="Estimate (minutes)"
                        type="number"
                        min="0"
                        value={form.estimate_minutes}
                        onChange={(e) => set('estimate_minutes', e.target.value)}
                    />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={saving} disabled={!form.title.trim()}>
                        Create task
                    </Button>
                </div>
            </form>
        </Modal>
    );
}