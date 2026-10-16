import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
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
    { value: 'office', labelKey: 'hrms.form.workOffice', fallback: 'Office' },
    { value: 'hybrid', labelKey: 'hrms.form.workHybrid', fallback: 'Hybrid' },
    { value: 'remote', labelKey: 'hrms.form.workRemote', fallback: 'Remote' },
];

const INITIAL_STATUSES = [
    { value: 'active', labelKey: 'hrms.form.statusActive', fallback: 'Active' },
    { value: 'on_notice', labelKey: 'hrms.form.statusNotice', fallback: 'On notice' },
    { value: 'on_leave', labelKey: 'hrms.form.statusLeave', fallback: 'On leave' },
    { value: 'suspended', labelKey: 'hrms.form.statusSuspended', fallback: 'Suspended' },
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
    { value: 'none', labelKey: 'hrms.form.loginNone' },
    { value: 'new', labelKey: 'hrms.form.loginNew' },
    { value: 'link', labelKey: 'hrms.form.loginLink' },
];

function optionLabel(t, option) {
    return option.labelKey ? t(option.labelKey, { defaultValue: option.fallback }) : option.label;
}

export default function EmployeeFormModal({ options, saving, onClose, onCreate }) {
    const { t } = useTranslation();
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
        <Modal open title={t('hrms.form.title')} subtitle={t('hrms.form.subtitle')} onClose={onClose} size="lg">
            <form onSubmit={submit} className="space-y-5 px-6 py-5">
                {errors.form && <Alert>{errors.form}</Alert>}

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">{t('hrms.form.identity')}</legend>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label={t('hrms.form.fullName')}
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={errors.name}
                            required
                            autoFocus
                        />
                        <Input
                            label={t('hrms.form.preferredName')}
                            value={form.preferred_name}
                            onChange={(e) => set('preferred_name', e.target.value)}
                            error={errors.preferred_name}
                        />
                        <Input
                            label={t('hrms.form.designation')}
                            value={form.designation}
                            onChange={(e) => set('designation', e.target.value)}
                            error={errors.designation}
                        />
                        <Select
                            label={t('hrms.form.employmentType')}
                            value={form.employment_type_id}
                            onChange={(e) => set('employment_type_id', e.target.value)}
                            error={errors.employment_type_id}
                        >
                            <option value="">{t('hrms.form.notSet')}</option>
                            {typeOptions.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">{t('hrms.form.employment')}</legend>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Input
                            label={t('hrms.form.joiningDate')}
                            type="date"
                            value={form.joining_date}
                            onChange={(e) => set('joining_date', e.target.value)}
                            error={errors.joining_date}
                        />
                        <Input
                            label={t('hrms.form.probationEnds')}
                            type="date"
                            value={form.probation_end_date}
                            onChange={(e) => set('probation_end_date', e.target.value)}
                            error={errors.probation_end_date}
                        />
                        <Select
                            label={t('hrms.form.workMode')}
                            value={form.work_mode}
                            onChange={(e) => set('work_mode', e.target.value)}
                            error={errors.work_mode}
                        >
                            {(options?.work_modes ?? WORK_MODES).map((mode) => (
                                <option key={mode.value} value={mode.value}>
                                    {optionLabel(t, mode)}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label={t('hrms.form.status')}
                            value={form.status}
                            onChange={(e) => set('status', e.target.value)}
                            error={errors.status}
                        >
                            {(options?.statuses ?? INITIAL_STATUSES).map((status) => (
                                <option key={status.value} value={status.value}>
                                    {optionLabel(t, status)}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label={t('hrms.form.manager')}
                            value={form.manager_id}
                            onChange={(e) => set('manager_id', e.target.value)}
                            error={errors.manager_id}
                            className="sm:col-span-2"
                        >
                            <option value="">{t('hrms.form.noManager')}</option>
                            {managerOptions.map((manager) => (
                                <option key={manager.id} value={manager.id}>
                                    {manager.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">{t('hrms.form.personal')}</legend>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Input
                            label={t('hrms.form.personalEmail')}
                            type="email"
                            value={form.personal_email}
                            onChange={(e) => set('personal_email', e.target.value)}
                            error={errors.personal_email}
                        />
                        <Input
                            label={t('hrms.form.phone')}
                            value={form.phone}
                            onChange={(e) => set('phone', e.target.value)}
                            error={errors.phone}
                        />
                        <Input
                            label={t('hrms.form.dob')}
                            type="date"
                            value={form.date_of_birth}
                            onChange={(e) => set('date_of_birth', e.target.value)}
                            error={errors.date_of_birth}
                        />
                        <Input
                            label={t('hrms.form.nationality')}
                            value={form.nationality}
                            onChange={(e) => set('nationality', e.target.value)}
                            error={errors.nationality}
                        />
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">{t('hrms.form.emergency')}</legend>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Input
                            label={t('hrms.form.emergencyName')}
                            value={form.emergency_contact_name}
                            onChange={(e) => set('emergency_contact_name', e.target.value)}
                            error={errors.emergency_contact_name}
                        />
                        <Input
                            label={t('hrms.form.emergencyPhone')}
                            value={form.emergency_contact_phone}
                            onChange={(e) => set('emergency_contact_phone', e.target.value)}
                            error={errors.emergency_contact_phone}
                        />
                        <Input
                            label={t('hrms.form.emergencyRelation')}
                            value={form.emergency_contact_relation}
                            onChange={(e) => set('emergency_contact_relation', e.target.value)}
                            error={errors.emergency_contact_relation}
                        />
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">{t('hrms.form.account')}</legend>
                    <Select
                        value={form.login}
                        onChange={(e) => set('login', e.target.value)}
                        error={errors.email ?? errors.user_id}
                    >
                        {LOGIN_MODES.map((mode) => (
                            <option key={mode.value} value={mode.value}>
                                {optionLabel(t, mode)}
                            </option>
                        ))}
                    </Select>

                    {form.login === 'new' && (
                        <div className="space-y-3 rounded-lg border border-gray-200 bg-gray-50/60 p-3">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Input
                                    label={t('hrms.form.workEmail')}
                                    type="email"
                                    value={form.email}
                                    onChange={(e) => set('email', e.target.value)}
                                    error={errors.email}
                                />
                                <Input
                                    label={t('hrms.form.password')}
                                    type="password"
                                    value={form.password}
                                    onChange={(e) => set('password', e.target.value)}
                                    error={errors.password}
                                />
                            </div>

                            {canPickRoles ? (
                                <div>
                                    <span className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.form.tenantRoles')}</span>
                                    <div className="flex flex-wrap gap-2">
                                        {roles.map((role) => (
                                            <label
                                                key={role.slug}
                                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 has-[:checked]:border-[var(--accent-ring)] has-[:checked]:bg-[var(--accent-soft)] has-[:checked]:text-[var(--accent-soft-text)]"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="h-3.5 w-3.5 rounded border-gray-300 accent-[var(--accent)]"
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
                                    {t('hrms.form.noRolesNote')}
                                </p>
                            )}
                        </div>
                    )}

                    {form.login === 'link' && (
                        <div className="space-y-2 rounded-lg border border-gray-200 bg-gray-50/60 p-3">
                            {canPickUsers ? (
                                <Select
                                    label={t('hrms.form.existingUser')}
                                    value={form.user_id}
                                    onChange={(e) => set('user_id', e.target.value)}
                                    error={errors.user_id}
                                >
                                    <option value="">{t('hrms.form.chooseUser')}</option>
                                    {users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} — {user.email}
                                        </option>
                                    ))}
                                </Select>
                            ) : (
                                <p className="text-xs text-gray-500">
                                    {t('hrms.form.noLinkNote')}
                                </p>
                            )}
                        </div>
                    )}
                </fieldset>

                <div className="flex justify-end gap-2 border-t border-gray-200 pt-4">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('hrms.form.cancel')}
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {saving ? t('hrms.form.creating') : t('hrms.form.create')}
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
