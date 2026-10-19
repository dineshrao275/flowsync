import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Select from '../ui/Select';
import Modal from '../ui/Modal';

/**
 * Filing a checklist item as a project task: pick the project, and the
 * item's title, description and due date move over (assigned to the
 * owner's login when that login sits on the project). The modal owns the
 * project list so both case pages share one picker instead of two.
 */
export default function ConvertTaskModal({ caseKind, caseId, item, onClose, onDone }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [projects, setProjects] = useState(null);
    const [projectId, setProjectId] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/projects')
            .then(({ data }) => setProjects(data.projects ?? []))
            .catch(() => setProjects([]));
    }, []);

    async function submit(e) {
        e.preventDefault();
        setErrors({});
        setSaving(true);

        try {
            await api.post(`/hrms/${caseKind}/cases/${caseId}/tasks/${item.id}/convert`, {
                project_id: Number(projectId),
            });
            toast.success(t('hrms.convert.converted'));
            onDone();
        } catch (err) {
            if (err.response?.status === 403) {
                setErrors({ form: t('hrms.convert.forbidden') });
            } else {
                setErrors(fieldErrors(err));
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open onClose={onClose} title={t('hrms.convert.title', { title: item.title })}>
            <form onSubmit={submit} className="space-y-4 px-6 py-5">
                <Select
                    label={t('hrms.convert.project')}
                    value={projectId}
                    onChange={(e) => setProjectId(e.target.value)}
                    error={errors.project_id}
                    required
                >
                    <option value="">{t('hrms.convert.select')}</option>
                    {(projects ?? []).map((project) => (
                        <option key={project.id} value={project.id}>
                            {project.workspace?.name ? `${project.workspace.name} / ` : ''}{project.name} ({project.key})
                        </option>
                    ))}
                </Select>
                {projects !== null && projects.length === 0 && (
                    <p className="text-sm text-gray-500">{t('hrms.convert.noProjects')}</p>
                )}
                {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('hrms.convert.cancel')}
                    </Button>
                    <Button type="submit" loading={saving} disabled={!projectId}>
                        {t('hrms.convert.submit')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
