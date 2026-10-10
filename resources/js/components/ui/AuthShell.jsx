export default function AuthShell({ title, subtitle, children, footer }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-[#FBFBFA] dark:bg-[#131720] px-4 py-10 transition-colors">
            <div className="w-full max-w-md">
                <div className="mb-8 flex items-center justify-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--accent)] text-lg font-bold text-[var(--accent-contrast)] shadow-sm">
                        F
                    </div>
                    <span className="text-xl font-semibold tracking-tight text-gray-900 dark:text-[#F3F4F6]">FlowSync</span>
                </div>

                <div className="rounded-2xl border border-gray-200 dark:border-[#2F3A4C] bg-white dark:bg-[#1A202C] p-8 shadow-sm">
                    <h1 className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-[#F3F4F6]">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-gray-500 dark:text-[#94A3B8]">{subtitle}</p>}
                    <div className="mt-6">{children}</div>
                </div>

                {footer && <div className="mt-6 text-center text-sm text-gray-500 dark:text-[#94A3B8]">{footer}</div>}
            </div>
        </div>
    );
}