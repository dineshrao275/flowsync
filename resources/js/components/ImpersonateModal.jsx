import { useEffect, useState } from 'react';
import api from '../services/api';
import Modal from './ui/Modal';
import Button from './ui/Button';
import { fieldClass } from './ui/fieldStyles';

const MIN_REASON = 8;

/**
 * Entering a tenant as one of its users is a recorded decision: the reason is
 * stored on the impersonation log and shown in the audit feed, and the session
 * is read-only unless the operator explicitly asks to make changes.
 */
export default function ImpersonateModal({ target, onCancel, onConfirm }) {
    const [reason, setReason] = useState('');
    const [allowChanges, setAllowChanges] = useState(false);
    const [busy, setBusy] = useState(false);
    const [grants, setGrants] = useState([]);
    const [consentRequired, setConsentRequired] = useState(false);
    const [grantId, setGrantId] = useState('');

    // Tenant-granted support windows (P8.4): bind the session to one so it carries the tenant's consent.
    useEffect(() => {
        if (!target) return;
        api.get('/system/support-access', { params: { tenant_id: target.tenant.id } })
            .then(({ data }) => {
                setGrants(data.grants);
                setConsentRequired(data.consent_required);
                setGrantId(data.grants[0] ? String(data.grants[0].id) : '');
            })
            .catch(() => {});
    }, [target]);

    useEffect(() => {
        if (target) {
            setReason('');
            setAllowChanges(false);
            setBusy(false);
        }
    }, [target]);

    const trimmed = reason.trim();
    const valid = trimmed.length >= MIN_REASON && (!consentRequired || grantId !== '');

    async function submit(event) {
        event.preventDefault();
        if (!valid || busy) return;
        setBusy(true);
        try {
            await onConfirm({ reason: trimmed, mode: allowChanges ? 'write' : 'read_only', grant_id: grantId ? Number(grantId) : undefined });
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal
            open={Boolean(target)}
            onClose={busy ? () => {} : onCancel}
            title="View as user"
            subtitle={target ? `${target.user.name} · ${target.tenant.name}` : undefined}
        >
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label htmlFor="impersonation-reason" className="mb-1 block text-sm font-medium text-gray-700">
                        Reason
                    </label>
                    <textarea
                        id="impersonation-reason"
                        rows={3}
                        maxLength={500}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. Ticket #1234 — customer cannot see their payroll run"
                        className={fieldClass}
                        autoFocus
                    />
                    <p className="mt-1 text-xs text-gray-500">
                        Recorded in the audit log. At least {MIN_REASON} characters.
                    </p>
                </div>

                {(grants.length > 0 || consentRequired) && (
                    <div>
                        <label htmlFor="impersonation-grant" className="mb-1 block text-sm font-medium text-gray-700">
                            Tenant consent
                        </label>
                        <select id="impersonation-grant" className={fieldClass} value={grantId} onChange={(e) => setGrantId(e.target.value)}>
                            {!consentRequired && <option value="">Without a grant</option>}
                            {grants.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.granted_by.name}: {g.mode === 'write' ? 'changes allowed' : 'read-only'}, until {new Date(g.expires_at).toLocaleString()}
                                </option>
                            ))}
                        </select>
                        {consentRequired && grants.length === 0 && (
                            <p className="mt-1 text-xs text-red-600">This tenant has not granted support access.</p>
                        )}
                    </div>
                )}

                <label className="flex items-start gap-2 text-sm text-gray-700">
                    <input
                        type="checkbox"
                        className="mt-0.5"
                        checked={allowChanges}
                        onChange={(e) => setAllowChanges(e.target.checked)}
                    />
                    <span>
                        Allow making changes
                        <span className="block text-xs text-gray-500">
                            Off by default: the session can only look. Even with this on, roles, users, billing,
                            exports and the company profile stay locked. The session ends automatically after a
                            fixed time.
                        </span>
                    </span>
                </label>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onCancel} disabled={busy}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={busy} disabled={!valid}>
                        Start session
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
