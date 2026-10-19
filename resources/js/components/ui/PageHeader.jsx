/**
 * The one page heading: title, optional description,
 * a right-aligned actions slot that wraps under the title on phones, and an
 * optional `tabs` row. The Topbar already renders the page's <h1> (the route
 * title), so the heading here is an <h2> by default — pass `as="h1"` only on a
 * page that renders without the Topbar. Pages migrate to this in U5–U7.
 */
export default function PageHeader({ title, description, actions, tabs, meta, as: Heading = 'h2', className = '' }) {
    return (
        <header className={`mb-6 ${className}`}>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <Heading className="truncate text-h1 font-bold text-fg">{title}</Heading>
                    {description && <p className="mt-1 max-w-2xl text-body text-fg-muted">{description}</p>}
                    {meta && <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div>}
                </div>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {tabs && <div className="mt-4">{tabs}</div>}
        </header>
    );
}
