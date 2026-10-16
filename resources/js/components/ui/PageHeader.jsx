/**
 * The one page heading: title (the page's only <h1>), optional description,
 * a right-aligned actions slot that wraps under the title on phones, and an
 * optional `tabs` row. Pages migrate to this in U3–U7 so spacing, heading
 * level and the action placement stop drifting page to page.
 */
export default function PageHeader({ title, description, actions, tabs, meta, className = '' }) {
    return (
        <header className={`mb-6 ${className}`}>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h1 className="truncate text-h1 font-bold text-fg">{title}</h1>
                    {description && <p className="mt-1 max-w-2xl text-body text-fg-muted">{description}</p>}
                    {meta && <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div>}
                </div>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {tabs && <div className="mt-4">{tabs}</div>}
        </header>
    );
}
