import { useState } from 'react';
import CustomFieldInputs, { useCustomFieldDefinitions } from '../customFields/CustomFieldInputs';
import { useTranslation } from 'react-i18next';
import { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';
import Modal from '../ui/Modal';
import Alert from '../ui/Alert';
import RichTextEditor from '../ui/RichTextEditor';

export default function CreateTaskModal({ options, topLevelTasks, projectKey, saving, onClose, onCreate }) {
    const { t } = useTranslation();
    const cfDefs = useCustomFieldDefinitions('task');
    const [form, setForm] = useState({
        custom_fields: {},
        title: '',
        description: '',
        issue_type_id: options.issue_types?.find((t) => !t.is_subtask)?.id ?? options.issue_types?.[0]?.id ?? '',
        version_id: '',
        epic_id: '',
        components: [],
        status_id: options.statuses.find((s) => s.is_default)?.id ?? options.statuses[0]?.id ?? '',
        priority_id: options.priorities.find((p) => p.is_default)?.id ?? '',
        assignee_id: '',
        parent_id: '',
        labels: [],
        start_date: '',
        due_date: '',
        story_points: '',
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

    function toggleComponent(id) {
        setForm((f) => ({
            ...f,
            components: f.components.includes(id) ? f.components.filter((c) => c !== id) : [...f.components, id],
        }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});
        onCreate({
            ...form,
            issue_type_id: form.issue_type_id || null,
            version_id: form.version_id || null,
            epic_id: form.epic_id || null,
            components: form.components,
            assignee_id: form.assignee_id || null,
            parent_id: form.parent_id || null,
            start_date: form.start_date || null,
            due_date: form.due_date || null,
            story_points: form.story_points === '' ? null : Number(form.story_points),
            estimate_minutes: form.estimate_minutes === '' ? null : Number(form.estimate_minutes),
            labels: form.labels,
        }).catch((err) => setErrors(fieldErrors(err)));
    }

    return (
        <Modal open title={t('tms.create.title')} subtitle={t('tms.create.subtitle', { key: projectKey })} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4 px-6 py-5">
                {errors.form && <Alert>{errors.form}</Alert>}
                <Input
                    label={t('tms.create.fieldTitle')}
                    name="task-title"
                    placeholder={t('tms.create.titlePlaceholder')}
                    value={form.title}
                    onChange={(e) => set('title', e.target.value)}
                    error={errors.title}
                    required
                    autoFocus
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    {options.issue_types?.length > 0 && (
                        <Select
                            label={t('tms.create.issueType')}
                            value={form.issue_type_id}
                            onChange={(e) => set('issue_type_id', e.target.value)}
                            error={errors.issue_type_id}
                        >
                            {options.issue_types.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </Select>
                    )}
                    {options.versions?.length > 0 ? (
                        <Select
                            label={t('tms.create.version')}
                            value={form.version_id}
                            onChange={(e) => set('version_id', e.target.value)}
                            error={errors.version_id}
                        >
                            <option value="">{t('tms.create.noVersion')}</option>
                            {options.versions.map((v) => (
                                <option key={v.id} value={v.id}>
                                    {v.name}
                                </option>
                            ))}
                        </Select>
                    ) : (
                        <div />
                    )}
                </div>
                {options.epics?.length > 0 && (
                    <Select label={t('tms.create.epic')} value={form.epic_id} onChange={(e) => set('epic_id', e.target.value)} error={errors.epic_id}>
                        <option value="">{t('tms.create.noEpic')}</option>
                        {options.epics.map((e) => (
                            <option key={e.id} value={e.id}>{e.key} · {e.title}</option>
                        ))}
                    </Select>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Select
                        label={t('tms.create.status')}
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
                        label={t('tms.create.priority')}
                        value={form.priority_id}
                        onChange={(e) => set('priority_id', e.target.value)}
                        error={errors.priority_id}
                    >
                        <option value="">{t('tms.create.none')}</option>
                        {options.priorities.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Select
                        label={t('tms.create.assignee')}
                        value={form.assignee_id}
                        onChange={(e) => set('assignee_id', e.target.value)}
                        error={errors.assignee_id}
                    >
                        <option value="">{t('tms.create.unassigned')}</option>
                        {options.assignees.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label={t('tms.create.parent')}
                        value={form.parent_id}
                        onChange={(e) => set('parent_id', e.target.value)}
                        error={errors.parent_id}
                    >
                        <option value="">{t('tms.create.none')}</option>
                        {topLevelTasks.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.key} · {t.title}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.create.description')}</label>
                    <RichTextEditor
                        value={form.description}
                        onChange={(html) => set('description', html)}
                        placeholder={t('tms.create.descriptionPlaceholder')}
                        error={errors.description}
                    />
                </div>
                {options.components?.length > 0 && (
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.create.components')}</label>
                        <div className="flex flex-wrap gap-2">
                            {options.components.map((comp) => (
                                <button
                                    key={comp.id}
                                    type="button"
                                    onClick={() => toggleComponent(comp.id)}
                                    className={`rounded-md px-2.5 py-1 text-xs font-medium transition ${
                                        form.components.includes(comp.id)
                                            ? 'bg-[var(--accent)] text-[var(--accent-contrast)]'
                                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    }`}
                                >
                                    {comp.name}
                                </button>
                            ))}
                        </div>
                        {errors.components && <p className="mt-1.5 text-sm text-red-600">{errors.components}</p>}
                    </div>
                )}
                {options.labels?.length > 0 && (
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.create.labels')}</label>
                        <div className="flex flex-wrap gap-2">
                            {options.labels.map((label) => (
                                <button
                                    key={label.id}
                                    type="button"
                                    onClick={() => toggleLabel(label.id)}
                                    className={`rounded-full px-3 py-1 text-xs font-medium transition ${
                                        form.labels.includes(label.id)
                                            ? 'bg-[var(--accent)] text-[var(--accent-contrast)]'
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
                        label={t('tms.create.startDate')}
                        type="date"
                        value={form.start_date}
                        onChange={(e) => set('start_date', e.target.value)}
                        error={errors.start_date}
                    />
                    <Input
                        label={t('tms.create.dueDate')}
                        type="date"
                        value={form.due_date}
                        onChange={(e) => set('due_date', e.target.value)}
                        error={errors.due_date}
                    />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        label={t('tms.create.storyPoints')}
                        type="number"
                        step="any"
                        min="0"
                        placeholder={t('tms.create.storyPointsPlaceholder')}
                        value={form.story_points}
                        onChange={(e) => set('story_points', e.target.value)}
                        error={errors.story_points}
                    />
                    <Input
                        label={t('tms.create.estimate')}
                        type="number"
                        min="0"
                        value={form.estimate_minutes}
                        onChange={(e) => set('estimate_minutes', e.target.value)}
                        error={errors.estimate_minutes}
                    />
                </div>
                <CustomFieldInputs fields={cfDefs} values={form.custom_fields} onChange={(v) => set('custom_fields', v)} errors={errors} />
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('tms.create.cancel')}
                    </Button>
                    <Button type="submit" loading={saving} disabled={!form.title.trim()}>
                        {t('tms.create.submit')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}