import { useState } from 'react';
import { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';

/**
 * Edit the profile fields of an existing employee.
 *
 * The field list is *not* the create form's field list, and the difference is
 * the API's rather than this component's: `status`, `manager_id`,
 * `employee_code` and every login field are rejected by name on update. Sending
 * them anyway would answer 200 while changing nothing, which is the worst
 * possible answer for a form that looks like it saved. Status lives with the
 * offboarding phase and the manager has its own control, so neither is a
 * missing feature here.
 *
 * Prefilled from the *masked* payload when the caller lacks the sensitive
 * permission — see the note on the personal block, since submitting a masked
 * value back would store `p***@***` as somebody's real address.
 */
export default function EmployeeEditModal({ employee, options, saving, onClose, onSave }) {
    const [form, setForm] = useState(() => initial(employee));
    const [errors, setErrors] = useState({});

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});

        onSave(payload(form, employee.restricted)).catch((err) => setErrors(fieldErrors(err)));
    }

    const typeOptions = options?.employment_types ?? [];

    return (
        <Modal open title="Edit employee" subtitle={employee.display_name} onClose={onClose} size="lg">
            <form onSubmit={submit} className="space-y-5 px-6 py-5">
                {errors.form && <Alert>{errors.form}</Alert>}

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Identity</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label="Full name"
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={errors.name}
                            required
                            autoFocus
                        />
                        <Input
                            label="Preferred name"
                            value={form.preferred_name}
                            onChange={(e) => set('preferred_name', e.target.value)}
                            error={errors.preferred_name}
                        />
                        <Input
                            label="Designation"
                            value={form.designation}
                            onChange={(e) => set('designation', e.target.value)}
                            error={errors.designation}
                        />
                        <Select
                            label="Employment type"
                            value={form.employment_type_id}
                            onChange={(e) => set('employment_type_id', e.target.value)}
                            error={errors.employment_type_id}
                        >
                            <option value="">Not set</option>
                            {typeOptions.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Employment</legend>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Input
                            label="Joining date"
                            type="date"
                            value={form.joining_date}
                            onChange={(e) => set('joining_date', e.target.value)}
                            error={errors.joining_date}
                        />
                        <Input
                            label="Probation ends"
                            type="date"
                            value={form.probation_end_date}
                            onChange={(e) => set('probation_end_date', e.target.value)}
                            error={errors.probation_end_date}
                        />
                        <Input
                            label="Confirmed on"
                            type="date"
                            value={form.confirmation_date}
                            onChange={(e) => set('confirmation_date', e.target.value)}
                            error={errors.confirmation_date}
                        />
                        <Select
                            label="Work mode"
                            value={form.work_mode}
                            onChange={(e) => set('work_mode', e.target.value)}
                            error={errors.work_mode}
                        >
                            {(options?.work_modes ?? []).map((mode) => (
                                <option key={mode.value} value={mode.value}>
                                    {mode.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Personal</legend>

                    {employee.restricted && (
                        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            You cannot see this record&rsquo;s personal fields, so they are left out of this form
                            rather than sent back as whatever the server masked them to.
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Input
                            label="Personal email"
                            type="email"
                            value={form.personal_email}
                            onChange={(e) => set('personal_email', e.target.value)}
                            error={errors.personal_email}
                        />
                        <Input
                            label="Phone"
                            value={form.phone}
                            onChange={(e) => set('phone', e.target.value)}
                            error={errors.phone}
                        />
                        <Input
                            label="Date of birth"
                            type="date"
                            value={form.date_of_birth}
                            onChange={(e) => set('date_of_birth', e.target.value)}
                            error={errors.date_of_birth}
                        />
                        <Input
                            label="Nationality"
                            value={form.nationality}
                            onChange={(e) => set('nationality', e.target.value)}
                            error={errors.nationality}
                        />
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Emergency contact</legend>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Input
                            label="Name"
                            value={form.emergency_contact_name}
                            onChange={(e) => set('emergency_contact_name', e.target.value)}
                            error={errors.emergency_contact_name}
                        />
                        <Input
                            label="Phone"
                            value={form.emergency_contact_phone}
                            onChange={(e) => set('emergency_contact_phone', e.target.value)}
                            error={errors.emergency_contact_phone}
                        />
                        <Input
                            label="Relation"
                            value={form.emergency_contact_relation}
                            onChange={(e) => set('emergency_contact_relation', e.target.value)}
                            error={errors.emergency_contact_relation}
                        />
                    </div>
                </fieldset>

                <div className="flex justify-end gap-2 border-t border-gray-200 pt-4">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {saving ? 'Saving…' : 'Save changes'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

const PERSONAL_FIELDS = [
    'personal_email',
    'phone',
    'date_of_birth',
    'gender',
    'marital_status',
    'nationality',
    'address_line1',
    'address_line2',
    'city',
    'state',
    'postal_code',
    'country',
    'emergency_contact_name',
    'emergency_contact_phone',
    'emergency_contact_relation',
];

function initial(employee) {
    return {
        name: employee.name ?? '',
        preferred_name: employee.preferred_name ?? '',
        designation: employee.designation ?? '',
        employment_type_id: employee.employment_type?.id ? String(employee.employment_type.id) : '',
        joining_date: employee.joining_date ?? '',
        probation_end_date: employee.probation_end_date ?? '',
        confirmation_date: employee.confirmation_date ?? '',
        work_mode: employee.work_mode ?? '',
        personal_email: employee.personal_email ?? '',
        phone: employee.phone ?? '',
        date_of_birth: employee.date_of_birth ?? '',
        nationality: employee.nationality ?? '',
        emergency_contact_name: employee.emergency_contact?.name ?? '',
        emergency_contact_phone: employee.emergency_contact?.phone ?? '',
        emergency_contact_relation: employee.emergency_contact?.relation ?? '',
    };
}

/**
 * Only the fields the form actually offers, with the empty ones dropped.
 *
 * A partial update is the point: an omitted key is untouched server-side, which
 * is what lets the personal block be skipped entirely for a caller who cannot
 * see it. Sending `''` instead would blank the field, and sending the masked
 * value would store `p***@***` as somebody's real address.
 */
function payload(form, restricted) {
    const body = {};

    Object.entries(form).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) return;
        body[key] = key === 'employment_type_id' ? Number(value) : value;
    });

    if (restricted) {
        PERSONAL_FIELDS.forEach((field) => delete body[field]);
    }

    return body;
}
