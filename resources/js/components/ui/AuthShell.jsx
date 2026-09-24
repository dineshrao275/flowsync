export default function AuthShell({ title, subtitle, children, footer }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-100 via-white to-slate-100 px-4 py-10">
            <div className="w-full max-w-md">
                <div className="mb-8 flex animate-fade-in-up items-center justify-center gap-3">
                    <div className="flex h-10 w-10 animate-bounce-soft items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white shadow-lg shadow-indigo-600/30">
                        F
                    </div>
                    <span className="text-xl font-semibold text-slate-900">FlowSync Admin</span>
                </div>

                <div className="animate-fade-in-up rounded-2xl border border-slate-200 bg-white p-8 shadow-xl shadow-slate-200/50" style={{ animationDelay: '60ms' }}>
                    <h1 className="text-2xl font-bold text-slate-900">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
                    <div className="mt-6">{children}</div>
                </div>

                {footer && <div className="mt-6 animate-fade-in text-center text-sm text-slate-500" style={{ animationDelay: '140ms' }}>{footer}</div>}
            </div>
        </div>
    );
}