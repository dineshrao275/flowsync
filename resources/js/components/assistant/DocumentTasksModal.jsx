import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Select from '../ui/Select';
import Spinner from '../ui/Spinner';
import ProposalReview from './ProposalReview';
import api from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useResource } from '../../hooks/useResource';
import useDocumentTasks from '../../hooks/useDocumentTasks';
import { fieldClass } from '../ui/fieldStyles';

const ACCEPT = '.txt,.md,.csv,.json,.docx,.pdf';

function InputStep({ onSubmit, error }) {
    const { t } = useTranslation();
    const { can } = useAuth();
    const { data, loading } = useResource((signal) => api.get('/projects', { signal }), []);
    // Mirrors the server rule for creating tasks; the server re-checks regardless.
    const projects = useMemo(
        () => (data?.data?.projects ?? []).filter((p) => !p.archived_at && (can('workspaces.manage') || ['lead', 'developer'].includes(p.my_role))),
        [data, can],
    );
    const [projectId, setProjectId] = useState('');
    const [file, setFile] = useState(null);
    const [text, setText] = useState('');
    const [instructions, setInstructions] = useState('');
    const ready = projectId && (file || text.trim());

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => { e.preventDefault(); if (ready) onSubmit({ projectId, file, text, instructions }); }}
        >
            {error ? <Alert type="error">{error.fields?.file?.[0] || error.fields?.text?.[0] || error.fields?.document_id?.[0] || error.message || t(`assistant.errors.${error.code}`, { defaultValue: t('assistant.errors.ai_error') })}</Alert> : null}
            {loading ? <Spinner /> : (
                <Select label={t('assistant.documents.project')} value={projectId} onChange={(e) => setProjectId(e.target.value)} required>
                    <option value="">{t('assistant.documents.chooseProject')}</option>
                    {projects.map((p) => <option key={p.id} value={p.id}>{p.key} · {p.name}</option>)}
                </Select>
            )}
            <div>
                <label htmlFor="ai-doc-file" className="mb-1.5 block text-sm font-medium text-gray-700">{t('assistant.documents.file')}</label>
                <input id="ai-doc-file" type="file" accept={ACCEPT} onChange={(e) => setFile(e.target.files?.[0] || null)} className={fieldClass} />
                <p className="mt-1 text-caption text-fg-subtle">{t('assistant.documents.formats')}</p>
            </div>
            {!file ? (
                <div>
                    <label htmlFor="ai-doc-text" className="mb-1.5 block text-sm font-medium text-gray-700">{t('assistant.documents.orPaste')}</label>
                    <textarea id="ai-doc-text" value={text} onChange={(e) => setText(e.target.value)} rows={6} maxLength={60000} className={`${fieldClass} resize-y`} placeholder={t('assistant.documents.pastePlaceholder')} />
                </div>
            ) : null}
            <div>
                <label htmlFor="ai-doc-guidance" className="mb-1.5 block text-sm font-medium text-gray-700">{t('assistant.documents.guidance')}</label>
                <input id="ai-doc-guidance" value={instructions} onChange={(e) => setInstructions(e.target.value)} maxLength={1000} className={fieldClass} placeholder={t('assistant.documents.guidancePlaceholder')} />
            </div>
            <p className="text-caption text-fg-subtle">{t('assistant.documents.privacy')}</p>
            <div className="flex justify-end"><Button type="submit" disabled={!ready}>{t('assistant.documents.generate')}</Button></div>
        </form>
    );
}

export default function DocumentTasksModal({ open, onClose }) {
    const { t } = useTranslation();
    const flow = useDocumentTasks();
    const { step, proposal, items, error } = flow;
    const created = items.filter((i) => i.result?.status === 'created').length;
    const failed = items.filter((i) => i.result?.status === 'failed').length;
    const selected = items.filter((i) => i.selected && i.title.trim() && i.result?.status !== 'created').length;

    function close() {
        if (step === 'review') flow.reject();
        flow.reset();
        onClose();
    }

    return (
        <Modal open={open} onClose={close} title={t('assistant.documents.title_modal')} subtitle={step === 'review' ? t('assistant.documents.reviewHint') : undefined} size="lg">
            <div className="p-5">
                {step === 'input' ? <InputStep onSubmit={flow.generate} error={error} /> : null}

                {step === 'working' ? (
                    <div className="flex flex-col items-center gap-3 py-10" role="status">
                        <Spinner />
                        <p className="text-sm text-fg-muted">{proposal ? t('assistant.documents.creating') : t('assistant.documents.reading')}</p>
                    </div>
                ) : null}

                {step === 'review' || step === 'done' ? (
                    <div className="space-y-4">
                        {error ? <Alert type="error">{error.fields?.form?.[0] || error.message || t(`assistant.errors.${error.code}`, { defaultValue: t('assistant.errors.ai_error') })}</Alert> : null}
                        {step === 'done' ? (
                            <Alert type={failed ? 'warning' : 'success'}>
                                {t('assistant.documents.summary', { created, failed })}
                                {proposal?.project_id ? <> <Link className="font-medium underline" to={`/projects/${proposal.project_id}?tab=tasks`} onClick={close}>{t('assistant.documents.openProject')}</Link></> : null}
                            </Alert>
                        ) : null}
                        <ProposalReview proposal={proposal} items={items} onEdit={flow.edit} onRemove={flow.remove} onAdd={flow.add} readOnly={step === 'done' && !failed} />
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={close}>{step === 'done' ? t('assistant.documents.close') : t('assistant.documents.discard')}</Button>
                            {step === 'review' || failed ? (
                                <Button onClick={flow.confirm} disabled={selected === 0}>{failed ? t('assistant.documents.retryFailed') : t('assistant.documents.create', { count: selected })}</Button>
                            ) : null}
                        </div>
                    </div>
                ) : null}
            </div>
        </Modal>
    );
}
