export default function EmptyState({ icon, title, description, hint, message, action }) {
    // `hint`/`message` are legacy aliases sixteen call sites pass instead
    // of `description` — React would silently drop them, so they fold in
    // here and every authored helper sentence renders again.
    const body = description ?? hint ?? message;

    return (
        <div className="flex flex-col items-center justify-center px-6 py-10 text-center">
            {icon && (
                <span className="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                    <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d={icon} />
                    </svg>
                </span>
            )}
            {title && <p className="text-sm font-semibold text-gray-800">{title}</p>}
            {body && <p className="mt-1 max-w-xs text-sm text-gray-500">{body}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}