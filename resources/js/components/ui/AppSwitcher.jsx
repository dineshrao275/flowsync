import { useState, useRef } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useClickOutside } from '../../hooks/useClickOutside';

/**
 * AppSwitcher — Figma "App Switcher / Suite" component: a Topbar pill that
 * opens a 300px dark (`#171C2C`, radius 12) popover with the FlowSync mark
 * and one chip per connected product (`#E9ECFF` HRMS / `#F0ECFF` TMS).
 */
export default function AppSwitcher({ currentModule = 'auto' }) {
    const [open, setOpen] = useState(false);
    const popoverRef = useRef(null);
    const navigate = useNavigate();
    const location = useLocation();

    useClickOutside(popoverRef, () => setOpen(false));

    let isHrms = location.pathname.startsWith('/hrms') || location.pathname.startsWith('/my');
    let isTms = location.pathname.startsWith('/projects') || location.pathname.startsWith('/workspaces') || location.pathname === '/dashboard';

    if (currentModule === 'hrms') {
        isHrms = true;
        isTms = false;
    } else if (currentModule === 'tms') {
        isTms = true;
        isHrms = false;
    }

    const switchLabel = isHrms ? 'Open TMS' : isTms ? 'Open HRMS' : 'Suite apps';

    return (
        <div className="relative inline-block" ref={popoverRef}>
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className="inline-flex h-8 items-center gap-1.5 rounded-[8px] bg-[var(--accent-soft)] px-3 text-[12px] font-semibold text-[var(--accent-soft-text)] transition-colors hover:bg-[var(--accent-soft-hover)]"
                title="Switch connected workspace"
            >
                <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                </svg>
                <span>{switchLabel}</span>
            </button>

            {open && (
                <div className="absolute right-0 top-full z-50 mt-2 w-[300px] animate-fade-in-up rounded-[12px] border border-white/5 bg-[#171c2c] p-3 text-white shadow-[0_18px_48px_-12px_rgba(9,12,22,0.55)]">
                    <div className="flex items-center gap-3 px-0.5">
                        <div className="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[10px] bg-[#4b5ef5] text-[16px] font-bold text-white">
                            F
                        </div>
                        <div className="min-w-0">
                            <div className="text-[13px] font-bold leading-tight text-white">FlowSync</div>
                            <div className="text-[10px] text-[#8f9bb3]">Connected suite</div>
                        </div>
                    </div>

                    <div className="mt-[7px] grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={() => { setOpen(false); navigate('/hrms'); }}
                            className={`h-[22px] rounded-[7px] text-[11px] font-semibold transition-colors ${
                                isHrms ? 'bg-[#4b5ef5] text-white' : 'bg-[#e9ecff] text-[#4b5ef5] hover:bg-[#dfe3fe]'
                            }`}
                        >
                            HRMS
                        </button>
                        <button
                            type="button"
                            onClick={() => { setOpen(false); navigate('/dashboard'); }}
                            className={`h-[22px] rounded-[7px] text-[11px] font-semibold transition-colors ${
                                isTms ? 'bg-[#4b5ef5] text-white' : 'bg-[#f0ecff] text-[#7858de] hover:bg-[#e6e0ff]'
                            }`}
                        >
                            TMS
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
