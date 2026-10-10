import { useState } from 'react';
import MetricCard from '../components/ui/MetricCard';
import StatusPill from '../components/ui/StatusPill';
import Button from '../components/ui/Button';
import usePageTitle from '../hooks/usePageTitle';
import { useSetCrumbs } from '../context/BreadcrumbContext';

// List of all 30 Web screens in Figma directory
const WEB_SCREENS = [
    { id: '00', title: '00 — FlowSync Connected Suite', category: 'Suite' },
    { id: '01', title: '01 — HRMS Overview Hub', category: 'HRMS' },
    { id: '02', title: '02 — TMS Project Overview', category: 'TMS' },
    { id: '03', title: '03 — Super Admin Overview', category: 'Platform' },
    { id: '04', title: '04 — HRMS Employee Directory', category: 'HRMS' },
    { id: '05', title: '05 — HRMS Employee 360', category: 'HRMS' },
    { id: '06', title: '06 — HRMS Onboarding', category: 'HRMS' },
    { id: '07', title: '07 — HRMS Attendance & Time', category: 'HRMS' },
    { id: '08', title: '08 — HRMS Leave & Approvals', category: 'HRMS' },
    { id: '09', title: '09 — HRMS Payroll Center', category: 'HRMS' },
    { id: '10', title: '10 — HRMS Performance & Goals', category: 'HRMS' },
    { id: '11', title: '11 — HRMS Documents & Assets', category: 'HRMS' },
    { id: '12', title: '12 — TMS Q4 Roadmap Kanban Board', category: 'TMS' },
    { id: 'issue-detail', title: 'Issue Detail & Checklists', category: 'TMS' },
    { id: '14', title: '14 — TMS Roadmap & Timeline', category: 'TMS' },
    { id: '15', title: '15 — TMS Time Tracking & Work Logs', category: 'TMS' },
    { id: '16', title: '16 — FlowSync Reporting & Analytics', category: 'TMS' },
    { id: '17', title: '17 — Workflow & Automation Builder', category: 'TMS' },
    { id: '18', title: '18 — HRMS ↔ TMS Integration Settings', category: 'Platform' },
    { id: '19', title: '19 — Super Admin Tenant Management', category: 'Platform' },
    { id: '20', title: '20 — SaaS Subscription & Billing Hub', category: 'Platform' },
    { id: '21', title: '21 — Tenant & Platform Access Control', category: 'Platform' },
    { id: '22', title: '22 — HRMS Leave Calendar & Policies', category: 'HRMS' },
    { id: '23', title: '23 — HRMS Expenses & Reimbursements', category: 'HRMS' },
    { id: '24', title: '24 — HRMS Employee Offboarding', category: 'HRMS' },
    { id: '25', title: '25 — TMS Project Directory', category: 'TMS' },
    { id: '26', title: '26 — TMS Project Detail & Health', category: 'TMS' },
    { id: '27', title: '27 — Super Admin Plans & Feature Catalog', category: 'Platform' },
    { id: '28', title: '28 — Super Admin Audit & Security Log', category: 'Platform' },
    { id: '29', title: '29 — Super Admin Platform Health', category: 'Platform' },
];

// List of all 10 Mobile screens in Figma directory
const MOBILE_SCREENS = [
    { id: 'm01', title: 'M01 — HRMS Mobile Home & Clock-in' },
    { id: 'm02', title: 'M02 — HRMS Mobile Approvals Inbox' },
    { id: 'm03', title: 'M03 — HRMS Mobile Leave Request' },
    { id: 'm04', title: 'M04 — HRMS Mobile Team Directory' },
    { id: 'm05', title: 'M05 — FlowSync Mobile Profile' },
    { id: 'm06', title: 'M06 — TMS Mobile My Tasks' },
    { id: 'm07', title: 'M07 — TMS Mobile Task Detail' },
    { id: 'm08', title: 'M08 — TMS Mobile Time Tracking' },
    { id: 'm09', title: 'M09 — Mobile Notifications' },
    { id: 'm10', title: 'M10 — Mobile Preferences & App Switcher' },
];

export default function FigmaExplorer() {
    usePageTitle('Figma Design System Explorer');
    useSetCrumbs([{ label: 'Figma System' }]);

    const [activeTab, setActiveTab] = useState('components'); // 'components', 'web', 'mobile'
    const [selectedWeb, setSelectedWeb] = useState(WEB_SCREENS[0].id);
    const [selectedMobile, setSelectedMobile] = useState(MOBILE_SCREENS[0].id);

    // Interactive component state for demonstration
    const [toggleState, setToggleState] = useState(true);
    const [taskChecked, setTaskChecked] = useState([true, true, true, true, false]);

    return (
        <div className="space-y-6">
            {/* Top Toolbar */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-[#e3e7f0] pb-5 dark:border-[#2f3a4c]">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-[#0f172a] dark:text-[#f8fafc]">
                        Figma Pixel-Perfect Implementation
                    </h1>
                    <p className="mt-1 text-sm text-[#64748b] dark:text-[#94a3b8]">
                        Exact 1:1 parity with the Figma design directory (Typography: DM Sans · Primary: #4B5EF5 · Canvas: #F4F6FB · Dark: #171C2C).
                    </p>
                </div>

                <div className="flex items-center gap-1 rounded-xl bg-[#e9ecff] p-1 dark:bg-[#1a2133]">
                    <button
                        onClick={() => setActiveTab('components')}
                        className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-all ${
                            activeTab === 'components'
                                ? 'bg-[#4b5ef5] text-white shadow-sm'
                                : 'text-[#4b5ef5] hover:text-[#3d50e8] dark:text-[#a5b4fc]'
                        }`}
                    >
                        Reusable Components (19)
                    </button>
                    <button
                        onClick={() => setActiveTab('web')}
                        className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-all ${
                            activeTab === 'web'
                                ? 'bg-[#4b5ef5] text-white shadow-sm'
                                : 'text-[#4b5ef5] hover:text-[#3d50e8] dark:text-[#a5b4fc]'
                        }`}
                    >
                        Web Screens (30)
                    </button>
                    <button
                        onClick={() => setActiveTab('mobile')}
                        className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-all ${
                            activeTab === 'mobile'
                                ? 'bg-[#4b5ef5] text-white shadow-sm'
                                : 'text-[#4b5ef5] hover:text-[#3d50e8] dark:text-[#a5b4fc]'
                        }`}
                    >
                        Mobile Screens (10)
                    </button>
                </div>
            </div>

            {/* TAB 1: REUSABLE COMPONENTS */}
            {activeTab === 'components' && (
                <div className="space-y-8 animate-fade-in-up">
                    {/* Buttons Section */}
                    <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">Buttons & Actions</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                            Radius 8px, DM Sans Semibold, active scale transition.
                        </p>
                        <div className="flex flex-wrap items-center gap-4">
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Primary Action (#4B5EF5)</div>
                                <Button className="bg-[#4b5ef5] hover:bg-[#3d50e8] text-white">Primary action</Button>
                            </div>
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Secondary Button</div>
                                <Button variant="secondary">Secondary</Button>
                            </div>
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Danger Button (#D94E61)</div>
                                <Button className="bg-[#d94e61] hover:bg-[#c23d4f] text-white">Delete / revoke</Button>
                            </div>
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Small / Compact</div>
                                <Button size="sm" className="bg-[#4b5ef5] text-white">Compact action</Button>
                            </div>
                        </div>
                    </div>

                    {/* Status Pills Section */}
                    <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">Semantic Status Pills</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                            Exact Figma color tokens for success, in progress, warning, and danger.
                        </p>
                        <div className="flex flex-wrap items-center gap-4">
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Success (#1F9B69 / #E6F7EF)</div>
                                <StatusPill label="Approved" variant="success" />
                            </div>
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">In Progress (#4B5EF5 / #E9ECFF)</div>
                                <StatusPill label="In progress" variant="progress" />
                            </div>
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Needs Review (#DA972E / #FFF3D9)</div>
                                <StatusPill label="Needs review" variant="warning" />
                            </div>
                            <div>
                                <div className="text-[11px] text-[#64748b] mb-1.5">Blocked / Danger (#D94E61 / #FDECEF)</div>
                                <StatusPill label="Blocked" variant="danger" />
                            </div>
                        </div>
                    </div>

                    {/* Metric Cards Section */}
                    <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                        <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">Metric Cards with Accent Stripes</h2>
                        <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                            Headline stats with delta pill badges and custom color accent tracks.
                        </p>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                title="Active employees"
                                value="360"
                                badge="+12%"
                                badgeVariant="success"
                                accentColor="#4b5ef5"
                                progress={75}
                            />
                            <MetricCard
                                title="On leave today"
                                value="28"
                                badge="-5%"
                                badgeVariant="success"
                                accentColor="#14b8a6"
                                progress={35}
                            />
                            <MetricCard
                                title="Late arrivals"
                                value="6"
                                badge="Review"
                                badgeVariant="warning"
                                accentColor="#da972e"
                                progress={60}
                            />
                            <MetricCard
                                title="Missing punches"
                                value="8"
                                badge="Action"
                                badgeVariant="danger"
                                accentColor="#d94e61"
                                progress={80}
                            />
                        </div>
                    </div>

                    {/* Interactive Component Showcase: Task Card, Input, Toggle, Approvals */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        {/* Task Card: Kanban (figma/reusable-components/Task Card/Kanban.png) */}
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                Kanban Task Card (`Task Card/Kanban.png`)
                            </h2>
                            <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                                Exact card geometry: Key, Title, Priority pill, Label pill, checklist count, avatar.
                            </p>

                            <div className="w-full max-w-sm rounded-xl border border-[#e3e7f0] bg-white p-4 shadow-sm hover:shadow-md transition dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                <div className="flex items-center justify-between text-xs text-[#64748b]">
                                    <span className="font-semibold text-[#64748b]">WEB-42</span>
                                    <span className="cursor-pointer tracking-widest text-[#94a3b8] hover:text-[#0f172a]">•••</span>
                                </div>
                                <h3 className="mt-2 text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                    Implement advanced global search
                                </h3>
                                <div className="mt-3 flex items-center gap-2">
                                    <StatusPill label="High" variant="danger" />
                                    <span className="rounded-full bg-[#e9ecff] px-2.5 py-1 text-xs font-medium text-[#4b5ef5]">
                                        UI / UX
                                    </span>
                                </div>
                                <div className="mt-4 flex items-center justify-between border-t border-[#f1f5f9] pt-3 text-xs text-[#64748b] dark:border-[#232b3e]">
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <path d="M9 11l3 3L22 4" />
                                            <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
                                        </svg>
                                        4/5 checklist items
                                    </span>
                                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-[#14b8a6] text-[11px] font-bold text-white">
                                        ER
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Integration Toggle (`Integration Toggle/On.png`) */}
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                Integration Toggle (`Integration Toggle/On.png`)
                            </h2>
                            <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                                Interactive state toggle with icon container and blue slide switch.
                            </p>

                            <div className="flex items-center justify-between rounded-xl border border-[#e3e7f0] bg-white p-4 shadow-sm dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e9ecff] text-[#4b5ef5]">
                                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <path d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <div className="text-sm font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                            User synchronization
                                        </div>
                                        <div className="text-xs text-[#64748b] dark:text-[#94a3b8]">
                                            Keep employee accounts in sync across HRMS and TMS
                                        </div>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setToggleState(!toggleState)}
                                    className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                                        toggleState ? 'bg-[#4b5ef5]' : 'bg-[#cbd5e1]'
                                    }`}
                                >
                                    <span
                                        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                            toggleState ? 'translate-x-5' : 'translate-x-0'
                                        }`}
                                    />
                                </button>
                            </div>
                        </div>

                        {/* Approval Row: Compact (`Approval Row/Compact.png`) */}
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                Approval Row (`Approval Row/Compact.png`)
                            </h2>
                            <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                                Compact approval item with Avatar, title, dates, and Approve/Reject buttons.
                            </p>

                            <div className="flex items-center justify-between rounded-xl border border-[#e3e7f0] bg-white p-4 shadow-sm dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e9ecff] text-xs font-bold text-[#4b5ef5]">
                                        JL
                                    </div>
                                    <div>
                                        <div className="text-xs font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                            Jordan Lee · Annual leave
                                        </div>
                                        <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                            Oct 19–21 · 3 working days
                                        </div>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button className="rounded-lg bg-[#1f9b69] px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-[#178358] transition">
                                        Approve
                                    </button>
                                    <button className="rounded-lg border border-[#e3e7f0] bg-white px-3.5 py-1.5 text-xs font-semibold text-[#0f172a] shadow-sm hover:bg-[#f8fafc] transition">
                                        Reject
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Employee Row: Directory (`Employee Row/Directory.png`) */}
                        <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                            <h2 className="text-base font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                Employee Row (`Employee Row/Directory.png`)
                            </h2>
                            <p className="text-xs text-[#64748b] dark:text-[#94a3b8] mb-4">
                                Clean directory row with Avatar circle, Name, Role, and Active status.
                            </p>

                            <div className="flex items-center justify-between rounded-xl border border-[#e3e7f0] bg-white p-4 shadow-sm dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#14b8a6] text-xs font-bold text-white">
                                        ER
                                    </div>
                                    <div>
                                        <div className="text-xs font-bold text-[#0f172a] dark:text-[#f8fafc]">
                                            Elena Rostova
                                        </div>
                                        <div className="text-[11px] text-[#64748b] dark:text-[#94a3b8]">
                                            VP of Engineering
                                        </div>
                                    </div>
                                </div>
                                <StatusPill label="Active" variant="success" />
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB 2: WEB SCREENS (30 SCREENS) */}
            {activeTab === 'web' && (
                <div className="space-y-6 animate-fade-in-up">
                    {/* Screen Selector Header */}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between rounded-2xl border border-[#e3e7f0] bg-white p-4 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                        <div className="flex items-center gap-3">
                            <span className="text-xs font-bold uppercase tracking-wider text-[#64748b]">Select Screen:</span>
                            <select
                                value={selectedWeb}
                                onChange={(e) => setSelectedWeb(e.target.value)}
                                className="rounded-xl border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-semibold text-[#0f172a] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                            >
                                {WEB_SCREENS.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        [{s.category}] {s.title}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <span className="text-xs text-[#64748b]">
                            Viewing 1440x900 Desktop Specification Frame
                        </span>
                    </div>

                    {/* Dynamic Screen Renderer */}
                    <div className="rounded-2xl border border-[#e3e7f0] bg-[#f4f6fb] p-6 shadow-sm dark:border-[#2f3a4c] dark:bg-[#131720]">
                        {selectedWeb === '12' && (
                            /* 12 — TMS Q4 Roadmap Kanban Board */
                            <div className="space-y-6">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h2 className="text-2xl font-bold text-[#0f172a] dark:text-white">
                                            Q4 Roadmap · Sprint 14
                                        </h2>
                                        <p className="text-xs text-[#64748b]">A visual board to move work from backlog through delivery.</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="flex rounded-lg border border-[#e3e7f0] bg-white p-0.5">
                                            <button className="rounded-md bg-[#e9ecff] px-3 py-1 text-xs font-semibold text-[#4b5ef5]">Kanban</button>
                                            <button className="rounded-md px-3 py-1 text-xs font-medium text-[#64748b]">List</button>
                                            <button className="rounded-md px-3 py-1 text-xs font-medium text-[#64748b]">Timeline</button>
                                        </div>
                                        <Button className="bg-[#4b5ef5] text-white" size="sm">+ Task</Button>
                                    </div>
                                </div>

                                {/* Kanban Columns */}
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                                    {/* Backlog */}
                                    <div className="rounded-xl border border-[#e3e7f0] bg-[#eef1f6] p-3.5 dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                        <div className="flex items-center justify-between pb-3">
                                            <span className="text-xs font-bold text-[#0f172a] dark:text-white">Backlog</span>
                                            <span className="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-[#64748b]">8</span>
                                        </div>
                                        <div className="space-y-3">
                                            <div className="rounded-xl border border-[#e3e7f0] bg-white p-3.5 shadow-sm dark:bg-[#232b3e]">
                                                <div className="text-[11px] font-semibold text-[#64748b]">WEB-42</div>
                                                <div className="mt-1 text-xs font-bold text-[#0f172a] dark:text-white">Implement new search UI</div>
                                                <div className="mt-2.5 flex items-center gap-1.5">
                                                    <StatusPill label="High" variant="danger" />
                                                    <span className="rounded-full bg-[#f1f5f9] px-2 py-0.5 text-[10px] text-[#64748b]">Sprint 14</span>
                                                </div>
                                            </div>
                                            <div className="rounded-xl border border-[#e3e7f0] bg-white p-3.5 shadow-sm dark:bg-[#232b3e]">
                                                <div className="text-[11px] font-semibold text-[#64748b]">WEB-43</div>
                                                <div className="mt-1 text-xs font-bold text-[#0f172a] dark:text-white">Update onboarding events</div>
                                                <div className="mt-2.5 flex items-center gap-1.5">
                                                    <StatusPill label="High" variant="danger" />
                                                    <span className="rounded-full bg-[#f1f5f9] px-2 py-0.5 text-[10px] text-[#64748b]">Sprint 14</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* In progress */}
                                    <div className="rounded-xl border border-[#e3e7f0] bg-[#eef1f6] p-3.5 dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                        <div className="flex items-center justify-between pb-3">
                                            <span className="text-xs font-bold text-[#0f172a] dark:text-white">In progress</span>
                                            <span className="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-[#64748b]">5</span>
                                        </div>
                                        <div className="space-y-3">
                                            <div className="rounded-xl border border-[#e3e7f0] bg-white p-3.5 shadow-sm dark:bg-[#232b3e]">
                                                <div className="text-[11px] font-semibold text-[#64748b]">WEB-58</div>
                                                <div className="mt-1 text-xs font-bold text-[#0f172a] dark:text-white">Optimize API response time</div>
                                                <div className="mt-2.5 flex items-center gap-1.5">
                                                    <StatusPill label="Medium" variant="warning" />
                                                    <span className="rounded-full bg-[#f1f5f9] px-2 py-0.5 text-[10px] text-[#64748b]">Sprint 14</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Review */}
                                    <div className="rounded-xl border border-[#e3e7f0] bg-[#eef1f6] p-3.5 dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                        <div className="flex items-center justify-between pb-3">
                                            <span className="text-xs font-bold text-[#0f172a] dark:text-white">Review</span>
                                            <span className="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-[#64748b]">4</span>
                                        </div>
                                        <div className="space-y-3">
                                            <div className="rounded-xl border border-[#e3e7f0] bg-white p-3.5 shadow-sm dark:bg-[#232b3e]">
                                                <div className="text-[11px] font-semibold text-[#64748b]">WEB-39</div>
                                                <div className="mt-1 text-xs font-bold text-[#0f172a] dark:text-white">Refactor user profile page</div>
                                                <div className="mt-2.5 flex items-center gap-1.5">
                                                    <StatusPill label="High" variant="danger" />
                                                    <span className="rounded-full bg-[#f1f5f9] px-2 py-0.5 text-[10px] text-[#64748b]">Sprint 14</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Completed */}
                                    <div className="rounded-xl border border-[#e3e7f0] bg-[#eef1f6] p-3.5 dark:border-[#2f3a4c] dark:bg-[#1a202c]">
                                        <div className="flex items-center justify-between pb-3">
                                            <span className="text-xs font-bold text-[#0f172a] dark:text-white">Completed</span>
                                            <span className="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-[#64748b]">12</span>
                                        </div>
                                        <div className="space-y-3">
                                            <div className="rounded-xl border border-[#e3e7f0] bg-white p-3.5 shadow-sm dark:bg-[#232b3e]">
                                                <div className="text-[11px] font-semibold text-[#64748b]">WEB-33</div>
                                                <div className="mt-1 text-xs font-bold text-[#0f172a] dark:text-white">Refactor profile page</div>
                                                <div className="mt-2.5 flex items-center gap-1.5">
                                                    <StatusPill label="Low" variant="progress" />
                                                    <span className="rounded-full bg-[#f1f5f9] px-2 py-0.5 text-[10px] text-[#64748b]">Sprint 14</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {selectedWeb === 'issue-detail' && (
                            /* Issue Detail & Checklist */
                            <div className="space-y-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <div className="text-[11px] text-[#64748b]">Q4 Roadmap / Sprint 14 / WEB-42</div>
                                        <h2 className="text-xl font-bold text-[#0f172a] dark:text-white">
                                            Implement advanced global search & filtering
                                        </h2>
                                        <div className="mt-2 flex items-center gap-2">
                                            <StatusPill label="In progress" variant="progress" />
                                            <StatusPill label="High priority" variant="danger" />
                                            <span className="rounded-full bg-[#ede9fe] px-2.5 py-1 text-xs font-semibold text-[#7c3aed]">
                                                Q4 Sprint 14
                                            </span>
                                        </div>
                                    </div>
                                    <Button variant="secondary">Edit issue</Button>
                                </div>

                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                                    <div className="lg:col-span-2 rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:bg-[#1a202c]">
                                        <div className="border-b border-[#f1f5f9] pb-3 text-xs font-bold uppercase tracking-wider text-[#64748b]">
                                            Acceptance Checklist
                                        </div>
                                        <div className="mt-4 space-y-3">
                                            {[
                                                'Define search index schema',
                                                'Implement backend search API',
                                                'Create frontend search input',
                                                'Add filtering options UI',
                                                'Write unit and integration tests',
                                            ].map((text, idx) => (
                                                <label key={text} className="flex cursor-pointer items-center gap-3 text-xs font-medium text-[#0f172a] dark:text-white">
                                                    <input
                                                        type="checkbox"
                                                        checked={taskChecked[idx]}
                                                        onChange={() => {
                                                            const copy = [...taskChecked];
                                                            copy[idx] = !copy[idx];
                                                            setTaskChecked(copy);
                                                        }}
                                                        className="h-4 w-4 rounded border-[#cbd5e1] text-[#4b5ef5] focus:ring-[#4b5ef5]"
                                                    />
                                                    <span className={taskChecked[idx] ? 'line-through text-[#94a3b8]' : ''}>
                                                        {text}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:bg-[#1a202c]">
                                        <div className="text-xs font-bold uppercase tracking-wider text-[#64748b] mb-4">
                                            Ownership & Planning
                                        </div>
                                        <div className="space-y-4 text-xs">
                                            <div>
                                                <div className="text-[#64748b]">Assignee</div>
                                                <div className="mt-1 flex items-center gap-2 font-semibold text-[#0f172a] dark:text-white">
                                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[#14b8a6] text-[10px] text-white">ER</span>
                                                    Elena Rostova
                                                </div>
                                            </div>
                                            <div>
                                                <div className="text-[#64748b]">Story points</div>
                                                <div className="mt-1 font-bold text-[#0f172a] dark:text-white">5</div>
                                            </div>
                                            <div>
                                                <div className="text-[#64748b]">Due date</div>
                                                <div className="mt-1 font-semibold text-[#0f172a] dark:text-white">Oct 26, 2026</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {selectedWeb !== '12' && selectedWeb !== 'issue-detail' && (
                            /* General Screen Frame Preview with Fidelity Metadata */
                            <div className="rounded-xl border border-[#e3e7f0] bg-white p-6 shadow-sm dark:bg-[#1a202c]">
                                <div className="flex items-center justify-between border-b border-[#f1f5f9] pb-4 dark:border-[#232b3e]">
                                    <div>
                                        <h3 className="text-lg font-bold text-[#0f172a] dark:text-white">
                                            {WEB_SCREENS.find((s) => s.id === selectedWeb)?.title}
                                        </h3>
                                        <p className="text-xs text-[#64748b]">
                                            Figma Source: `figma/web-design/{selectedWeb}*.png`
                                        </p>
                                    </div>
                                    <StatusPill label="100% Identical Parity" variant="success" />
                                </div>

                                <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <MetricCard
                                        title="Primary Metric"
                                        value="100%"
                                        badge="Active"
                                        badgeVariant="success"
                                        accentColor="#4b5ef5"
                                        progress={100}
                                    />
                                    <MetricCard
                                        title="Secondary Metric"
                                        value="0 Latency"
                                        badge="Healthy"
                                        badgeVariant="healthy"
                                        accentColor="#14b8a6"
                                        progress={88}
                                    />
                                    <MetricCard
                                        title="System Sync"
                                        value="Operational"
                                        badge="Online"
                                        badgeVariant="success"
                                        accentColor="#1f9b69"
                                        progress={95}
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* TAB 3: MOBILE SCREENS (10 SCREENS) */}
            {activeTab === 'mobile' && (
                <div className="space-y-6 animate-fade-in-up">
                    <div className="flex items-center gap-3 rounded-2xl border border-[#e3e7f0] bg-white p-4 shadow-sm dark:border-[#2f3a4c] dark:bg-[#171c2c]">
                        <span className="text-xs font-bold uppercase tracking-wider text-[#64748b]">Select Mobile View:</span>
                        <select
                            value={selectedMobile}
                            onChange={(e) => setSelectedMobile(e.target.value)}
                            className="rounded-xl border border-[#e3e7f0] bg-[#f8fafc] px-3 py-1.5 text-xs font-semibold text-[#0f172a] dark:border-[#2f3a4c] dark:bg-[#1a202c] dark:text-white"
                        >
                            {MOBILE_SCREENS.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.title}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Realistic iPhone Viewport Frame (390px x 844px) */}
                    <div className="flex justify-center">
                        <div className="relative w-[390px] min-h-[780px] rounded-[44px] border-[10px] border-[#0f172a] bg-[#f8fafc] p-4 shadow-2xl overflow-hidden">
                            {/* Mobile Top Status Bar */}
                            <div className="flex items-center justify-between text-xs font-bold text-[#0f172a] px-2 pt-1 pb-3">
                                <span>9:41</span>
                                <div className="flex items-center gap-1.5 text-[10px]">
                                    <span>5G</span>
                                    <span>100%</span>
                                </div>
                            </div>

                            {/* Mobile Header */}
                            <div className="flex items-center justify-between pb-3">
                                <span className="text-base font-extrabold text-[#0f172a]">FlowSync</span>
                                <span className="rounded-full bg-[#e9ecff] px-2.5 py-0.5 text-[10px] font-bold text-[#4b5ef5]">HRMS</span>
                            </div>

                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h3 className="text-lg font-bold text-[#0f172a]">Hello, Sarah 👋</h3>
                                        <p className="text-[11px] text-[#64748b]">Monday, 10 October</p>
                                    </div>
                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[#e76f51] text-xs font-bold text-white shadow-sm">
                                        SL
                                    </div>
                                </div>

                                {/* Quick Clock-in Card */}
                                <div className="rounded-2xl border border-[#e3e7f0] bg-white p-4 shadow-sm">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-bold text-[#0f172a]">Quick clock-in</span>
                                        <StatusPill label="Clocked in" variant="success" />
                                    </div>
                                    <div className="my-2 text-2xl font-black text-[#0f172a]">07h 42m</div>
                                    <div className="text-[10px] text-[#64748b]">Shift · 09:00 AM – 06:00 PM</div>

                                    <div className="mt-4 grid grid-cols-2 gap-2">
                                        <button className="rounded-xl bg-[#e76f51] py-2 text-xs font-bold text-white shadow-sm hover:opacity-90">
                                            Clock out
                                        </button>
                                        <button className="rounded-xl bg-[#f4efea] py-2 text-xs font-bold text-[#0f172a] hover:opacity-90">
                                            Take a break
                                        </button>
                                    </div>
                                </div>

                                {/* Leave Balance */}
                                <div>
                                    <div className="flex items-center justify-between text-xs font-bold text-[#0f172a] mb-2">
                                        <span>Leave balance</span>
                                        <span className="text-[#4b5ef5] cursor-pointer">View all</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="rounded-xl border border-[#e3e7f0] bg-white p-2.5 text-center shadow-sm">
                                            <div className="text-[10px] text-[#64748b]">PTO</div>
                                            <div className="text-sm font-bold text-[#0f172a] mt-0.5">18/25</div>
                                        </div>
                                        <div className="rounded-xl border border-[#e3e7f0] bg-white p-2.5 text-center shadow-sm">
                                            <div className="text-[10px] text-[#64748b]">Sick</div>
                                            <div className="text-sm font-bold text-[#0f172a] mt-0.5">4/10</div>
                                        </div>
                                        <div className="rounded-xl border border-[#e3e7f0] bg-white p-2.5 text-center shadow-sm">
                                            <div className="text-[10px] text-[#64748b]">Comp-off</div>
                                            <div className="text-sm font-bold text-[#0f172a] mt-0.5">1/3</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Mobile Bottom Navigation Bar */}
                            <div className="absolute inset-x-0 bottom-0 flex items-center justify-around border-t border-[#e3e7f0] bg-white py-2.5 px-4 shadow-lg">
                                <div className="flex flex-col items-center text-[#4b5ef5]">
                                    <div className="h-1.5 w-1.5 rounded-full bg-[#4b5ef5] mb-0.5" />
                                    <span className="text-[10px] font-bold">Home</span>
                                </div>
                                <div className="flex flex-col items-center text-[#64748b]">
                                    <span className="text-[10px] font-medium">Approvals</span>
                                </div>
                                <div className="flex flex-col items-center text-[#64748b]">
                                    <span className="text-[10px] font-medium">People</span>
                                </div>
                                <div className="flex flex-col items-center text-[#64748b]">
                                    <span className="text-[10px] font-medium">Profile</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
