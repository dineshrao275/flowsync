import { useTranslation } from 'react-i18next';
import Skeleton from './Skeleton';

const SIZES = {
    sm: { lines: 2, heading: false, cards: 0 },
    md: { lines: 4, heading: false, cards: 0 },
    lg: { lines: 5, heading: true, cards: 3 },
};

/**
 * The loading state for a block of content: a heading, optional stat cards and rows of text that
 * hold the shape of what is about to land. Use it wherever data is fetched instead of a centred
 * spinner, so the page does not jump when the real content arrives. Announces "Loading" once.
 */
export default function ContentSkeleton({ size = 'md', className = '' }) {
    const { t } = useTranslation();
    const spec = SIZES[size] ?? SIZES.md;

    return (
        <div role="status" aria-label={t('admin.common.loadingLabel')} className={`space-y-4 py-2 ${className}`}>
            {spec.heading && (
                <div className="space-y-2">
                    <Skeleton decorative className="h-7 w-1/3" />
                    <Skeleton decorative variant="text" className="w-1/2" />
                </div>
            )}
            {spec.cards > 0 && (
                <div className="grid gap-3 sm:grid-cols-3">
                    {Array.from({ length: spec.cards }, (_, i) => <Skeleton key={i} decorative variant="block" className="h-24" />)}
                </div>
            )}
            <div className="space-y-3">
                {Array.from({ length: spec.lines }, (_, i) => (
                    <div key={i} className="flex items-center gap-3">
                        <Skeleton decorative variant="circle" className="h-8 w-8" />
                        <div className="flex-1 space-y-2">
                            <Skeleton decorative className={i % 2 ? 'w-2/3' : 'w-5/6'} />
                            <Skeleton decorative variant="text" className="w-1/3" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
