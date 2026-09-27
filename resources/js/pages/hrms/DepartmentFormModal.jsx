import { useState } from 'react';
import { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import { fieldClass } from '../../components/ui/fieldStyles';

/**
 * Create or edit a department.
 *
 * One modal for both verbs because the two payloads differ by exactly one
 * field: on a create the name is required, on an edit it is optional so a
 * rename that changes nothing else cannot be submitted as a blank name. That
 * asymmetry is the request's rule, not the form's, so it is expressed once
 * here rather than guessed per button.
 *
 * `departments` is the *whole* flat list, not just the roots. A department's
 * parent is picked from anywhere in the org, and the tree can re-parent a
 * branch under any node — the backend rejects the cycles, and a picker that
 * only offered roots would make a legal move impossible to express.
 */
export default function DepartmentFormModal({ department, departments, heads, saving, onClose, onSubmit }) {
    const editing = Boolean(department);

    const [form, setForm] = useState(() => ({
        name: department?.name ?? '',
        code: department?.code ?? '',
        description: department?.description ?? '',
        parent_id: department?.parent_id ?? '',
        head_employee_id: department?.head_employee_id ?? '',
        is_active: department ? department.is_active : true,
    }));
    const [errors, setErrors] = useState({});

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});

        // A picker that has not been touched must not send `''` for an integer
        // column: the rule is `exists:departments,id`, and an empty string
        // fails it with a message about a department that does not exist. `null`
        // is what "no parent" and "no head" actually mean.
        const payload = {
            ...form,
            code: form.code.trim() || null,
            description: form.description.trim() || null,
            parent_id: form.parent_id === '' ? null : Number(form.parent_id),
            head_employee_id: form.head_employee_id === '' ? null : Number(form.head_employee_id),
        };

        onSubmit(payload).catch((err) => setErrors(fieldErrors(err)));
    }

    // A department cannot be its own parent, and offering the node in its own
    // parent picker turns a request the backend will always reject into one the
    // form lets a user compose. Deeper descendants are still offered: the
    // service's cycle check is the authority, and a picker that tried to
    // reproduce that walk would be a second implementation of the same graph.
    const parentOptions = departments.filter((d) => d.id !== department?.id);

    return (
        <Modal
            open
            title={editing ? 'Edit department' : 'New department'}
            subtitle={editing ? department.name : 'A node in the org tree'}
            onClose={onClose}
            size="md"
        >
            <form onSubmit={submit} className="space-y-5 px-6 py-5">
                {errors.form && <Alert>{errors.form}</Alert>}

                <Input
                    label="Name"
                    value={form.name}
                    onChange={(e) => set('name', e.target.value)}
                    error={errors.name}
                    required
                    autoFocus
                />

                <Input
                    label="Code"
                    value={form.code}
                    onChange={(e) => set('code', e.target.value)}
                    error={errors.code}
                    placeholder="Optional — a short label like ENG or SALES"
                />

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">Description</label>
                    <textarea
                        className={`${fieldClass} min-h-20`}
                        rows={3}
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                        placeholder="What this team is responsible for…"
                    />
                    {errors.description && (
                        <p className="mt-1 text-sm text-red-600">{errors.description}</p>
                    )}
                </div>

                <Select
                    label="Reports to"
                    value={form.parent_id}
                    onChange={(e) => set('parent_id', e.target.value)}
                    error={errors.parent_id}
                >
                    <option value="">No parent — a root department</option>
                    {parentOptions.map((d) => (
                        <option key={d.id} value={d.id}>
                            {d.name}
                        </option>
                    ))}
                </Select>

                <Select
                    label="Department head"
                    value={form.head_employee_id}
                    onChange={(e) => set('head_employee_id', e.target.value)}
                    error={errors.head_employee_id}
                >
                    <option value="">No head yet</option>
                    {heads.map((person) => (
                        <option key={person.id} value={person.id}>
                            {person.name}
                        </option>
                    ))}
                </Select>

                {editing && (
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            checked={form.is_active}
                            onChange={(e) => set('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-gray-300"
                        />
                        Active
                    </label>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={saving}>
                        {editing ? 'Save changes' : 'Create department'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
