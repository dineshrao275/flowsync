import { useTranslation } from 'react-i18next';

export default function Spinner({ className = '' }) {
    const { t } = useTranslation();

    return (
        <div
            className={`h-8 w-8 animate-spin rounded-full border-2 border-gray-300 border-t-[var(--accent)] ${className}`}
            role="status"
            aria-label={t('admin.common.loadingLabel')}
        >
            <span className="sr-only">{t('admin.common.loading')}</span>
        </div>
    );
}
