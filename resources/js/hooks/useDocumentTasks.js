import { useCallback, useState } from 'react';
import api, { fieldErrors } from '../services/api';

/**
 * Document -> reviewable proposal -> confirmed tasks.
 * Nothing is created until `confirm`; the server holds the proposal and the edits, and re-checks
 * permission at that moment. `step` is: input | working | review | done.
 */
export default function useDocumentTasks() {
    const [step, setStep] = useState('input');
    const [proposal, setProposal] = useState(null);
    const [items, setItems] = useState([]);
    const [error, setError] = useState(null);

    const fail = useCallback((caught) => {
        const status = caught?.response?.status;
        const body = caught?.response?.data || {};
        setError({ message: body.message || null, code: body.code || (status ? `http_${status}` : 'network'), fields: fieldErrors(caught) });
    }, []);

    const load = (next) => {
        setProposal(next);
        setItems(next.items);
    };

    const generate = useCallback(async ({ projectId, file, text, instructions }) => {
        setError(null);
        setStep('working');
        try {
            let documentId = null;
            if (file) {
                const form = new FormData();
                form.append('file', file);
                form.append('project_id', projectId);
                const { data } = await api.post('/ai/documents', form, { headers: { 'Content-Type': 'multipart/form-data' } });
                documentId = data.document.id;
            }
            const { data } = await api.post('/ai/proposals', {
                project_id: Number(projectId),
                document_id: documentId,
                text: file ? null : text,
                instructions: instructions || null,
            });
            load(data.proposal);
            setStep('review');
        } catch (caught) {
            fail(caught);
            setStep('input');
        }
    }, [fail]);

    const edit = useCallback((id, patch) => setItems((all) => all.map((item) => (item.id === id ? { ...item, ...patch } : item))), []);
    const remove = useCallback((id) => setItems((all) => all.filter((item) => item.id !== id)), []);
    const add = useCallback(() => setItems((all) => [...all, { id: `new-${Date.now()}-${all.length}`, title: '', description: '', priority: null, story_points: null, selected: true, duplicate_of: null, kind: 'task', result: null }]), []);

    const confirm = useCallback(async () => {
        if (!proposal) return;
        setError(null);
        setStep('working');
        try {
            // Server ids are uuids; client-added rows have none and get one server-side.
            const payload = items.map(({ id, title, description, priority, story_points: points, selected, kind }) => ({
                id: String(id).startsWith('new-') ? undefined : id, title, description, priority, story_points: points, selected, kind,
            }));
            await api.put(`/ai/proposals/${proposal.id}`, { items: payload });
            const { data } = await api.post(`/ai/proposals/${proposal.id}/confirm`);
            load(data.proposal);
            setStep('done');
        } catch (caught) {
            fail(caught);
            setStep('review');
        }
    }, [proposal, items, fail]);

    const reject = useCallback(async () => {
        if (proposal) await api.post(`/ai/proposals/${proposal.id}/reject`).catch(() => {});
    }, [proposal]);

    const reset = useCallback(() => {
        setStep('input');
        setProposal(null);
        setItems([]);
        setError(null);
    }, []);

    return { step, proposal, items, error, generate, edit, remove, add, confirm, reject, reset };
}
