import { useTranslation } from 'react-i18next';
import Avatar from '../../components/ui/Avatar';
import Card from '../../components/ui/Card';
import Timestamp from '../../components/ui/Timestamp';

/**
 * The status ledger: every transition this record has been through, newest
 * first, with who made it.
 *
 * Read-only and deliberately not a filter — an employment record's history is
 * evidence, and letting a reader hide a transition from it would defeat the
 * point of keeping it. The actor can be null: `actor_user_id` is
 * `nullOnDelete`, so the account behind a change can be deleted while the change
 * itself must survive.
 *
 * @param {{ from_status: string|null, to_status: string, from_status_label: string|null, to_status_label: string, reason: string|null, note: string|null, effective_date: string|null, created_at: string, actor: { id: number, name: string }|null }[]} history
 */
export default function StatusHistory({ history }) {
    const { t } = useTranslation();
    return (
        <Card title={t('hrms.history.title')} dense className="overflow-hidden">

            {history.length === 0 ? (
                <p className="px-4 py-3 text-sm text-gray-500">{t('hrms.history.empty')}</p>
            ) : (
                <ol className="divide-y divide-gray-100">
                    {history.map((entry) => (
                        <li key={entry.id} className="px-4 py-3">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="text-sm font-medium text-gray-800">
                                    {entry.from_status_label ? `${entry.from_status_label} → ` : ''}
                                    {entry.to_status_label}
                                </span>
                                <span className="whitespace-nowrap text-xs text-gray-400">
                                    <Timestamp date={entry.effective_date ?? entry.created_at} />
                                </span>
                            </div>

                            {(entry.reason || entry.note) && (
                                <p className="mt-1 text-xs text-gray-500">
                                    {[entry.reason, entry.note].filter(Boolean).join(' — ')}
                                </p>
                            )}

                            <p className="mt-1.5 flex items-center gap-1.5 text-xs text-gray-400">
                                <Avatar name={entry.actor?.name ?? '?'} size="sm" />
                                {entry.actor?.name ?? t('hrms.history.accountDeleted')}
                            </p>
                        </li>
                    ))}
                </ol>
            )}
        </Card>
    );
}
