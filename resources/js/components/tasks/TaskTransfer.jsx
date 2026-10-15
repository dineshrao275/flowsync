import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Modal from '../ui/Modal';
import Select from '../ui/Select';

/**
 * Clone and move-to-project actions for one task (P4.6). The server decides what survives a
 * move; the response's `dropped` counts are surfaced so nothing disappears silently.
 */
export default function TaskTransfer({ projectId, task, canClone, canMoveProject, onDone }) {
    const toast = useToast();
    const [mode, setMode] = useState(null); // 'clone' | 'move'
    const [projects, setProjects] = useState([]);
    const [targetId, setTargetId] = useState(String(projectId));
    const [title, setTitle] = useState(`Clone of ${task.title}`);
    const [withSubtasks, setWithSubtasks] = useState(false);
    const [targetStatuses, setTargetStatuses] = useState([]);
    const [statusMap, setStatusMap] = useState({});
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!mode) return;
        api.get('/projects').then(({ data }) => setProjects((data.projects || []).filter((p) => !p.archived_at)));
    }, [mode]);

    useEffect(() => {
        if (mode !== 'move' || String(targetId) === String(projectId)) { setTargetStatuses([]); return; }
        api.get(`/projects/${targetId}/statuses`).then(({ data }) => setTargetStatuses(data.statuses || []));
    }, [mode, targetId, projectId]);

    function close() {
        setMode(null);
        setErrors({});
    }

    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            if (mode === 'clone') {
                const { data } = await api.post(`/projects/${projectId}/tasks/${task.id}/clone`, {
                    title,
                    target_project_id: Number(targetId),
                    include_subtasks: withSubtasks,
                });
                toast.success(`Cloned as ${data.task.key}.`);
            } else {
                const map = Object.fromEntries(Object.entries(statusMap).filter(([, v]) => v));
                const { data } = await api.post(`/projects/${projectId}/tasks/${task.id}/move-project`, {
                    target_project_id: Number(targetId),
                    status_map: map,
                });
                const dropped = Object.entries(data.dropped || {}).map(([k, v]) => `${v} ${k.replace('_', ' ')}`).join(', ');
                toast.success(`Moved as ${data.task.key}.${dropped ? ` Dropped: ${dropped}.` : ''}`);
            }
            close();
            onDone?.();
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setBusy(false);
        }
    }

    const others = projects.filter((p) => String(p.id) !== String(projectId));

    return (
        <>
            {canClone && <Button type="button" variant="secondary" onClick={() => { setTargetId(String(projectId)); setMode('clone'); }}>Clone</Button>}
            {canMoveProject && <Button type="button" variant="secondary" onClick={() => { setTargetId(''); setMode('move'); }}>Move…</Button>}
            {mode && (
                <Modal open title={mode === 'clone' ? `Clone ${task.key}` : `Move ${task.key}`} subtitle={mode === 'move' ? 'Gets a new key in the target project.' : ''} onClose={close}>
                    <form onSubmit={submit} className="space-y-4 px-6 py-5">
                        {(errors.form || errors.target_project_id) && <Alert>{errors.form || errors.target_project_id}</Alert>}
                        {mode === 'clone' && <Input label="Title" value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} />}
                        <Select label="Project" value={targetId} onChange={(e) => setTargetId(e.target.value)}>
                            {mode === 'move' && <option value="">Choose a project…</option>}
                            {(mode === 'clone' ? projects : others).map((p) => <option key={p.id} value={p.id}>{p.key} · {p.name}</option>)}
                        </Select>
                        {mode === 'clone' && (
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={withSubtasks} onChange={(e) => setWithSubtasks(e.target.checked)} /> Clone sub-tasks too
                            </label>
                        )}
                        {mode === 'move' && task.status && targetStatuses.length > 0 && (
                            <Select
                                label={`Status "${task.status.name}" becomes`}
                                value={statusMap[task.status_id] ?? ''}
                                onChange={(e) => setStatusMap({ [task.status_id]: e.target.value })}
                                error={errors.status_map}
                            >
                                <option value="">Match automatically</option>
                                {targetStatuses.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </Select>
                        )}
                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="secondary" onClick={close}>Cancel</Button>
                            <Button type="submit" loading={busy} disabled={!targetId}>{mode === 'clone' ? 'Clone' : 'Move'}</Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}
