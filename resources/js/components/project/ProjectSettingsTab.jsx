import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Card from '../ui/Card';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import RichTextEditor from '../ui/RichTextEditor';
import SaveAsTemplate from './SaveAsTemplate';

/**
 * Project settings tab: name/description and project duration settings.
 */
export default function ProjectSettingsTab({
    project,
    settingsDates,
    onDatesChange,
    formErrors,
    updating,
    updateProject,
    deleteProject,
    hasModule,
    projectId,
}) {
    const { t } = useTranslation();

    return (
        <Card title={t('tms.project.settingsTitle')} subtitle={t('tms.project.settingsSubtitle', { key: project.key })}>
            <SettingsForm
                project={project}
                settingsDates={settingsDates}
                onDatesChange={onDatesChange}
                formErrors={formErrors}
                updating={updating}
                updateProject={updateProject}
                deleteProject={deleteProject}
            />
            {hasModule('project_templates') && <SaveAsTemplate projectId={projectId} defaultName={project.name} />}
        </Card>
    );
}

/**
 * Project settings form: name/description stay uncontrolled (read on
 * submit, exactly as before) while the two dates are controlled through
 * the shared calendar Input — which has no uncontrolled mode. Dates fall
 * back to the loaded project until typed, and reset on project switch
 * (settingsDates null) and after a successful save.
 */
function SettingsForm({ project, settingsDates, onDatesChange, formErrors, updating, updateProject, deleteProject }) {
    const { t } = useTranslation();
    const dates = settingsDates ?? { start_date: project.start_date ?? '', due_date: project.due_date ?? '' };
    const [description, setDescription] = useState(project.description ?? '');

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                updateProject({
                    name: e.currentTarget.name.value,
                    description: description || undefined,
                    start_date: dates.start_date || null,
                    due_date: dates.due_date || null,
                });
            }}
        >
            {formErrors.form && <Alert>{formErrors.form}</Alert>}
            <Input label={t('tms.project.settingsName')} name="name" defaultValue={project.name} error={formErrors.name} required />
            <div>
                <label className="mb-1.5 block text-sm font-medium text-gray-700">{t('tms.project.settingsDesc')}</label>
                <RichTextEditor
                    value={description}
                    onChange={setDescription}
                    placeholder={t('tms.project.settingsDescPlaceholder')}
                    error={formErrors.description}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Input
                    label={t('tms.project.settingsStart')}
                    type="date"
                    value={dates.start_date}
                    onChange={(e) => onDatesChange({ ...dates, start_date: e.target.value })}
                    error={formErrors.start_date}
                />
                <Input
                    label={t('tms.project.settingsEnd')}
                    type="date"
                    value={dates.due_date}
                    onChange={(e) => onDatesChange({ ...dates, due_date: e.target.value })}
                    error={formErrors.due_date}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit" loading={updating}>
                    {t('tms.project.save')}
                </Button>
                <Button type="button" variant="danger" onClick={deleteProject}>
                    {t('tms.project.deleteProject')}
                </Button>
            </div>
        </form>
    );
}
