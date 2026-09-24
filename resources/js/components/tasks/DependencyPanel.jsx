import { useEffect, useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Badge from '../ui/Badge';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import Button from '../ui/Button';

export default function DependencyPanel({ task, projectId, canManage, allTasks }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);
    const [formErrors, setFormErrors] = useState({});

    function fetchDependencies() {
        api.get(`/projects/${projectId}/tasks/${task.id}/dependencies`)
            .then(({ data }) => {
                setData(data);
                setError(null);
            })
            .catch(() => setError('Could not load dependencies.'));
    }

    useEffect(() => {
        fetchDependencies();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, task.id]);

    function add(e) {
        e.preventDefault();
        if (!selected) return;
        setBusy(true);
        setFormErrors({});
        api.post(`/projects/${projectId}/tasks/${task.id}/dependencies`, {
            depends_on_task_id: Number(selected),
            type: 'blocks',
        })
            .then(() => {
                setSelected('');
                fetchDependencies();
            })
            .catch((err) => setFormErrors(fieldErrors(err)))
            .finally(() => setBusy(false));
    }

    function remove(id) {
        api.delete(`/projects/${projectId}/tasks/${task.id}/dependencies/${id}`)
            .then(fetchDependencies)
            .catch(() => { });
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!data) {
        return (
            <div className="flex justify-center py-10">
                <Spinner />
            </div>
        );
    }

    const candidates = allTasks.filter((t) => t.id !== task.id);

    function DepRow({ dependency }) {
        const name = dependency.task
            ? `${dependency.task.key} · ${dependency.task.title}`
            : dependency.dependsOn
                ? `${dependency.dependsOn.key} · ${dependency.dependsOn.title}`
                : `Task #${dependency.depends_on_task_id}`;
        return (
            <li className="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2">
                <span className="text-sm text-gray-700">{name}</span>
                {canManage && (
                    <button
                        type="button"
                        className="text-xs text-gray-400 transition hover:text-red-600"
                        onClick={() => remove(dependency.id)}
                    >
                        Remove
                    </button>
                )}
            </li>
        );
    }

    return (
        <div className="space-y-5">
            <div>
                <p className="mb-2 text-sm font-medium text-gray-700">
                    Blocked by <Badge>{data.blocked_by.length}</Badge>
                </p>
                {data.blocked_by.length === 0 ? (
                    <p className="text-sm text-gray-400">Nothing is blocking this task.</p>
                ) : (
                    <ul className="space-y-2">
                        {data.blocked_by.map((dependency) => (
                            <DepRow key={dependency.id} dependency={dependency} />
                        ))}
                    </ul>
                )}
            </div>

            <div>
                <p className="mb-2 text-sm font-medium text-gray-700">
                    Blocks <Badge>{data.blocks.length}</Badge>
                </p>
                {data.blocks.length === 0 ? (
                    <p className="text-sm text-gray-400">This task is not blocking anything.</p>
                ) : (
                    <ul className="space-y-2">
                        {data.blocks.map((dependency) => (
                            <DepRow key={dependency.id} dependency={dependency} />
                        ))}
                    </ul>
                )}
            </div>

            {canManage && (
                <form onSubmit={add} className="space-y-2 border-t border-gray-100 pt-4">
                    <label className="block text-sm font-medium text-gray-700">Add dependency</label>
                    <div className="flex gap-2">
                        <select
                            className="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                            value={selected}
                            onChange={(e) => setSelected(e.target.value)}
                        >
                            <option value="">Select a task…</option>
                            {candidates.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.key} · {t.title}
                                </option>
                            ))}
                            {candidates.length === 0 && <option value="">No other tasks</option>}
                        </select>
                        <button
                            type="button"
                            className="rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-500 hover:bg-gray-50"
                            onClick={() => setSelected('')}
                            title="Clear"
                        >
                            ✕
                        </button>
                    </div>
                    {formErrors.depends_on_task_id && <Alert>{formErrors.depends_on_task_id}</Alert>}
                    {formErrors.form && <Alert>{formErrors.form}</Alert>}
                    <Button size="sm" type="submit" loading={busy} disabled={!selected}>
                        Add
                    </Button>
                </form>
            )}
        </div>
    );
}