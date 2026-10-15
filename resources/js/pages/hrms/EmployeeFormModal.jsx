import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import { useAuth } from '../../context/AuthContext';

/**
 * Fallback only. `filterOptions.work_modes` comes from the PHP enum in the same
 * response, and the modal renders before that request has landed — so this list
 * exists for that first paint, not as a second copy to keep in step.
 */
const WORK_MODES = [
    { value: 'office', label: 'Office' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'remote', label: 'Remote' },
];

const INITIAL_STATUSES = [
    { value: 'active', label: 'Active' },
    { value: 'on_notice', label: 'On notice' },
    { value: 'on_leave', label: 'On leave' },
    { value: 'suspended', label: 'Suspended' },
];

/**
 * How the new record gets an account, if it does.
 *
 * Three ways, not two, because "no login at all" is a real case: a contractor or
 * a walk-in whose details we keep without an account that can sign in. Making
 * that a first-class choice is also what stops the inline-login fields from
 * being the *default* state of a new-person form, which is how a directory ends
 * up with accounts nobody chose to create.
 */
const LOGIN_MODES = [
    { value: 'none', label: 'No sign-in account' },
    { value: 'new', label: 'Create a sign-in account' },
    { value: 'link', label: 'Link an existing user' },
];

export default function EmployeeFormModal({ options, saving, onClose, onCreate }) {
    const { can } = useAuth();
    const [form, setForm] = useState(() => initial(options));
    const [errors, setErrors] = useState({});
    const [roles, setRoles] = useState([]);
    const [users, setUsers] = useState([]);

    // Both pickers are permission-gated, and the fetch is skipped rather than
    // made and failed: a 403 in the console for a form the user is allowed to
    // fill in is noise, and the plan's own convention is to skip.
    const canPickRoles = can('permission:roles.view');
    const canPickUsers = can('permission:users.view');

    useEffect(() => {
        if (!canPickRoles) return;
        api.get('/roles').then(({ data: response }) => setRoles(response.roles ?? response ?? [])).catch(() => {});
    }, [canPickRoles]);

    useEffect(() => {
        if (!canPickUsers || form.login !== 'link') return;
        api
            .get('/users', { params: { per_page: 200 } })
            .then(({ data: response }) => setUsers(response.users ?? []))
            .catch(() => {});
    }, [canPickUsers, form.login]);

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function toggleRole(slug) {
        setForm((f) => ({
            ...f,
            roles: f.roles.includes(slug) ? f.roles.filter((r) => r !== slug) : [...f.roles, slug],
        }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});

        // The payload carries the profile and then *only* the login fields that
        // apply to the chosen mode. Sending all three modes' fields at once is
        // what the API rejects as an ambiguous create, and a form that cannot
        // express "none" would force every save to carry a `null` password.
        const payload = {
            ...profile(form),
            ...loginFields(form),
        };

        onCreate(payload).catch((err) => setErrors(fieldErrors(err)));
    }

    const typeOptions = options?.employment_types ?? [];
    const managerOptions = options?.managers ?? [];

    return (
        <Modal open title="New employee" subtitle="An employment record, and optionally a sign-in account" onClose={onClose} size="lg">
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
                        <Select
                            label="Work mode"
                            value={form.work_mode}
                            onChange={(e) => set('work_mode', e.target.value)}
                            error={errors.work_mode}
                        >
                            {(options?.work_modes ?? WORK_MODES).map((mode) => (
                                <option key={mode.value} value={mode.value}>
                                    {mode.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label="Status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value)}
                            error={errors.status}
                        >
                            {(options?.statuses ?? INITIAL_STATUSES).map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label="Manager"
                            value={form.manager_id}
                            onChange={(e) => set('manager_id', e.target.value)}
                            error={errors.manager_id}
                            className="sm:col-span-2"
                        >
                            <option value="">No manager</option>
                            {managerOptions.map((manager) => (
                                <option key={manager.id} value={manager.id}>
                                    {manager.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Personal</legend>
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

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Sign-in account</legend>
                    <Select
                        value={form.login}
                        onChange={(e) => set('login', e.target.value)}
                        error={errors.email ?? errors.user_id}
                    >
                        {LOGIN_MODES.map((mode) => (
                            <option key={mode.value} value={mode.value}>
                                {mode.label}
                            </option>
                        ))}
                    </Select>

                    {form.login === 'new' && (
                        <div className="space-y-3 rounded-lg border border-gray-200 bg-gray-50/60 p-3">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input
                                    label="Work email"
                                    type="email"
                                    value={form.email}
                                    onChange={(e) => set('email', e.target.value)}
                                    error={errors.email}
                                />
                                <Input
                                    label="Password"
                                    type="password"
                                    value={form.password}
                                    onChange={(e) => set('password', e.target.value)}
                                    error={errors.password}
                                />
                            </div>

                            {canPickRoles ? (
                                <div>
                                    <span className="mb-1.5 block text-sm font-medium text-gray-700">Tenant roles</span>
                                    <div className="flex flex-wrap gap-2">
                                        {roles.map((role) => (
                                            <label
                                                key={role.slug}
                                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50 has-[:checked]:text-indigo-700"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    checked={form.roles.includes(role.slug)}
                                                    onChange={() => toggleRole(role.slug)}
                                                />
                                                {role.name}
                                            </label>
                                        ))}
                                    </div>
                                    {errors.roles && <p className="mt-1.5 text-sm text-red-600">{errors.roles}</p>}
                                </div>
                            ) : (
                                <p className="text-xs text-gray-500">
                                    The account will be created with no roles — you do not have permission to read
                                    this tenant&rsquo;s roles.
                                </p>
                            )}
                        </div>
                    )}

                    {form.login === 'link' && (
                        <div className="space-y-2 rounded-lg border border-gray-200 bg-gray-50/60 p-3">
                            {canPickUsers ? (
                                <Select
                                    label="Existing user"
                                    value={form.user_id}
                                    onChange={(e) => set('user_id', e.target.value)}
                                    error={errors.user_id}
                                >
                                    <option value="">Choose a user</option>
                                    {users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} — {user.email}
                                        </option>
                                    ))}
                                </Select>
                            ) : (
                                <p className="text-xs text-gray-500">
                                    You do not have permission to list users, so an existing account cannot be
                                    linked from here.
                                </p>
                            )}
                        </div>
                    )}
                </fieldset>

                <div className="flex justify-end gap-2 border-t border-gray-200 pt-4">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {saving ? 'Creating…' : 'Create employee'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function initial(options) {
    return {
        name: '',
        preferred_name: '',
        designation: '',
        employment_type_id: '',
        joining_date: new Date().toISOString().slice(0, 10),
        probation_end_date: '',
        work_mode: 'office',
        status: (options?.statuses ?? INITIAL_STATUSES)[0]?.value ?? 'active',
        manager_id: '',

        personal_email: '',
        phone: '',
        date_of_birth: '',
        nationality: '',

        emergency_contact_name: '',
        emergency_contact_phone: '',
        emergency_contact_relation: '',

        // Default is no account: a form that opens with a password box invites
        // an account nobody meant to create.
        login: 'none',
        email: '',
        password: '',
        roles: [],
        user_id: '',
    };
}

/** Only the profile fields, with the empty ones dropped. */
function profile(form) {
    const payload = {};

    Object.entries(form).forEach(([key, value]) => {
        if (['login', 'email', 'password', 'roles', 'user_id'].includes(key)) return;
        if (value === '' || value === null || value === undefined) return;
        payload[key] = value;
    });

    return payload;
}

/** Only the fields for the chosen login mode. */
function loginFields(form) {
    if (form.login === 'new') {
        return { email: form.email, password: form.password, roles: form.roles };
    }

    if (form.login === 'link') {
        return { user_id: form.user_id };
    }

    return {};
}
