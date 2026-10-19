import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import Modal from './ui/Modal';
import Button from './ui/Button';
import { fieldClass } from './ui/fieldStyles';
import { formatDateTime } from '../utils/format';

const MIN_REASON = 8;

/**
 * Entering a tenant as one of its users is a recorded decision: the reason is
 * stored on the impersonation log and shown in the audit feed, and the session
 * is read-only unless the operator explicitly asks to make changes.
 */
export default function ImpersonateModal({ target, onCancel, onConfirm }) {
    const { t } = useTranslation();
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
            title={t('shell.impersonate.title')}
            subtitle={target ? `${target.user.name} · ${target.tenant.name}` : undefined}
        >
            <form onSubmit={submit} className="space-y-4 px-6 py-5">
                <div>
                    <label htmlFor="impersonation-reason" className="mb-1 block text-sm font-medium text-gray-700">
                        {t('shell.impersonate.reason')}
                    </label>
                    <textarea
                        id="impersonation-reason"
                        rows={3}
                        maxLength={500}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder={t('shell.impersonate.reasonPlaceholder')}
                        className={fieldClass}
                        autoFocus
                    />
                    <p className="mt-1 text-xs text-gray-500">
                        {t('shell.impersonate.reasonHint', { min: MIN_REASON })}
                    </p>
                </div>

                {(grants.length > 0 || consentRequired) && (
                    <div>
                        <label htmlFor="impersonation-grant" className="mb-1 block text-sm font-medium text-gray-700">
                            {t('shell.impersonate.tenantConsent')}
                        </label>
                        <select id="impersonation-grant" className={fieldClass} value={grantId} onChange={(e) => setGrantId(e.target.value)}>
                            {!consentRequired && <option value="">{t('shell.impersonate.withoutGrant')}</option>}
                            {grants.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {t(g.mode === 'write' ? 'shell.impersonate.grantOptionWrite' : 'shell.impersonate.grantOptionRead', {
                                        name: g.granted_by.name,
                                        date: formatDateTime(g.expires_at),
                                    })}
                                </option>
                            ))}
                        </select>
                        {consentRequired && grants.length === 0 && (
                            <p className="mt-1 text-xs text-red-600">{t('shell.impersonate.noGrant')}</p>
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
                        {t('shell.impersonate.allowChanges')}
                        <span className="block text-xs text-gray-500">
                            {t('shell.impersonate.allowChangesHint')}
                        </span>
                    </span>
                </label>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onCancel} disabled={busy}>
                        {t('admin.common.cancel')}
                    </Button>
                    <Button type="submit" loading={busy} disabled={!valid}>
                        {t('shell.impersonate.startSession')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
