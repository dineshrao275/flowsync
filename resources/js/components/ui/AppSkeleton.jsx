import { useTranslation } from 'react-i18next';
import Skeleton from './Skeleton';
import ContentSkeleton from './ContentSkeleton';

/** The whole shell (sidebar, top bar, page) as placeholders, shown while the session is being checked. */
export default function AppSkeleton() {
    const { t } = useTranslation();

    return (
        <div role="status" aria-label={t('admin.common.loadingLabel')} className="flex min-h-screen bg-gray-100">
            <aside className="hidden w-64 shrink-0 space-y-3 border-r border-gray-200 bg-white p-4 lg:block">
                <Skeleton decorative className="h-8 w-32" />
                {Array.from({ length: 8 }, (_, i) => <Skeleton key={i} decorative className="h-8 w-full" />)}
            </aside>
            <div className="flex-1">
                <div className="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-6">
                    <Skeleton decorative className="h-5 w-40" />
                    <Skeleton decorative variant="circle" className="h-8 w-8" />
                </div>
                <div className="p-6"><ContentSkeleton size="lg" /></div>
            </div>
        </div>
    );
}
