import { useState } from 'react';
import { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';

/**
 * Create or edit a designation.
 *
 * `level` is a band, not a sort key the server derives: the catalog decides
 * which numbers mean what (a payslip prints "L4"), so the form offers a plain
 * number and leaves the interpretation to the tenant's own convention. It is
 * optional, because a small tenant names its roles and never bands them.
 *
 * A designation can be scoped to one department (`department_id`), which is why
 * the whole department list is passed in rather than just the selected node.
 */
export default function DesignationFormModal({ designation, departments, saving, onClose, onSubmit }) {
    const editing = Boolean(designation);

    const [form, setForm] = useState(() => ({
        name: designation?.name ?? '',
        code: designation?.code ?? '',
        level: designation?.level ?? '',
        department_id: designation?.department_id ?? '',
    }));
    const [errors, setErrors] = useState({});

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});

        onSubmit({
            ...form,
            code: form.code.trim() || null,
            // An untouched select is `''`, which is not a valid integer for the
            // `nullable` rule to accept as "no band" — `null` is.
            level: form.level === '' ? null : Number(form.level),
            department_id: form.department_id === '' ? null : Number(form.department_id),
        }).catch((err) => setErrors(fieldErrors(err)));
    }

    return (
        <Modal
            open
            title={editing ? 'Edit designation' : 'New designation'}
            subtitle={editing ? designation.name : 'A job title in the tenant’s own catalogue'}
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

                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label="Code"
                        value={form.code}
                        onChange={(e) => set('code', e.target.value)}
                        error={errors.code}
                        placeholder="Optional — e.g. ENG"
                    />
                    <Input
                        label="Level"
                        type="number"
                        min="1"
                        value={form.level}
                        onChange={(e) => set('level', e.target.value)}
                        error={errors.level}
                        placeholder="Optional band number"
                    />
                </div>

                <Select
                    label="Department"
                    value={form.department_id}
                    onChange={(e) => set('department_id', e.target.value)}
                    error={errors.department_id}
                >
                    <option value="">Applies tenant-wide</option>
                    {departments.map((d) => (
                        <option key={d.id} value={d.id}>
                            {d.name}
                        </option>
                    ))}
                </Select>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={saving}>
                        {editing ? 'Save changes' : 'Create designation'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
