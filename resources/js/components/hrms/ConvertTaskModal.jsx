import { useEffect, useState } from 'react';
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
            toast.success('Checklist item converted.');
            onDone();
        } catch (err) {
            if (err.response?.status === 403) {
                setErrors({ form: 'You cannot file tasks into this project.' });
            } else {
                setErrors(fieldErrors(err));
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <Modal open onClose={onClose} title={`Convert “${item.title}”`}>
            <form onSubmit={submit} className="space-y-4">
                <Select
                    label="Project"
                    value={projectId}
                    onChange={(e) => setProjectId(e.target.value)}
                    error={errors.project_id}
                    required
                >
                    <option value="">Select…</option>
                    {(projects ?? []).map((project) => (
                        <option key={project.id} value={project.id}>
                            {project.workspace?.name ? `${project.workspace.name} / ` : ''}{project.name} ({project.key})
                        </option>
                    ))}
                </Select>
                {projects !== null && projects.length === 0 && (
                    <p className="text-sm text-gray-500">No project you may file into. Join one first.</p>
                )}
                {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={saving} disabled={!projectId}>
                        Create task
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
