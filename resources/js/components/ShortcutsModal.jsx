import { useTranslation } from 'react-i18next';
import Modal from './ui/Modal';

/** The `?` sheet: every global shortcut, grouped. Rows depend on what the caller can reach. */
export default function ShortcutsModal({ open, onClose, rows }) {
    const { t } = useTranslation();

    return (
        <Modal open={open} onClose={onClose} title={t('shell.shortcuts.title')} size="sm">
            <dl className="divide-y divide-line px-6 py-2">
                {rows.map((row) => (
                    <div key={row.label} className="flex items-center justify-between gap-4 py-2.5 text-sm">
                        <dt className="text-fg">{row.label}</dt>
                        <dd className="flex items-center gap-1">
                            {row.keys.map((key, index) => (
                                <span key={key} className="flex items-center gap-1">
                                    {index > 0 && <span className="text-caption text-fg-subtle">{t('shell.shortcuts.then')}</span>}
                                    <kbd className="rounded border border-line-strong bg-surface-sunken px-1.5 py-0.5 text-caption font-medium text-fg-muted">{key}</kbd>
                                </span>
                            ))}
                        </dd>
                    </div>
                ))}
            </dl>
        </Modal>
    );
}
