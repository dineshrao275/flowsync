import { useTranslation } from 'react-i18next';
import Button from './Button';

/**
 * Inline "this section failed to load" with a retry — the shared answer to
 * pages that previously showed a toast and an empty screen. `role="alert"`
 * so the failure is announced where it happens.
 */
export default function ErrorState({ title, message, onRetry, className = '' }) {
    const { t } = useTranslation();

    return (
        <div role="alert" className={`flex flex-col items-center justify-center gap-2 rounded-xl border border-danger-line bg-danger-soft px-6 py-8 text-center ${className}`}>
            <p className="text-sm font-semibold text-danger-fg">{title ?? t('admin.common.loadFailed')}</p>
            {message && <p className="max-w-md text-sm text-fg-muted">{message}</p>}
            {onRetry && (
                <Button variant="secondary" size="sm" onClick={onRetry} className="mt-2">
                    {t('admin.common.retry')}
                </Button>
            )}
        </div>
    );
}
