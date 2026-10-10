export default function Skeleton({ className = '', variant = 'text' }) {
    const base = 'animate-pulse bg-gray-200/70 dark:bg-[#252E3E] rounded-md';

    if (variant === 'circle') {
        return <div className={`shrink-0 rounded-full ${base} ${className}`} />;
    }

    if (variant === 'rect') {
        return <div className={`rounded-xl ${base} ${className}`} />;
    }

    return <div className={`h-4 ${base} ${className}`} />;
}

export function SkeletonCard({ lines = 3, className = '' }) {
    return (
        <div className={`rounded-xl border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#1A202C] p-5 shadow-sm space-y-3 ${className}`}>
            <div className="flex items-center gap-3">
                <Skeleton variant="circle" className="h-9 w-9" />
                <div className="flex-1 space-y-1.5">
                    <Skeleton className="w-1/3 h-4" />
                    <Skeleton className="w-1/4 h-3" />
                </div>
            </div>
            <div className="space-y-2 pt-1">
                {Array.from({ length: lines }).map((_, i) => (
                    <Skeleton key={i} className={`h-3 ${i === lines - 1 ? 'w-2/3' : 'w-full'}`} />
                ))}
            </div>
        </div>
    );
}

export function SkeletonTable({ rows = 5, cols = 4 }) {
    return (
        <div className="overflow-hidden rounded-xl border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#1A202C] p-4 shadow-sm">
            <div className="space-y-3">
                <div className="flex gap-4 border-b border-gray-100 dark:border-[#2F3A4C] pb-3">
                    {Array.from({ length: cols }).map((_, i) => (
                        <Skeleton key={i} className="h-4 flex-1" />
                    ))}
                </div>
                {Array.from({ length: rows }).map((_, r) => (
                    <div key={r} className="flex gap-4 py-2 border-b border-gray-50 dark:border-[#252E3E]/50 last:border-0">
                        {Array.from({ length: cols }).map((_, c) => (
                            <Skeleton key={c} className="h-3.5 flex-1" />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
