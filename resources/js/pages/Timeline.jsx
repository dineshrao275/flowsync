import { useState } from 'react';
import StatusPill from '../components/ui/StatusPill';
import Modal from '../components/ui/Modal';
import Input from '../components/ui/Input';
import Button from '../components/ui/Button';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';

const INITIATIVES = [
    {
        id: 1,
        title: 'Q4 search experience',
        owner: 'ER',
        ownerColor: '#4B5EF5',
        status: 'In progress',
        variant: 'progress',
        bar: { startPercent: 0, widthPercent: 70, color: '#4B5EF5' },
    },
    {
        id: 2,
        title: 'Performance hardening',
        owner: 'MC',
        ownerColor: '#1F9B69',
        status: 'In progress',
        variant: 'progress',
        bar: { startPercent: 35, widthPercent: 65, color: '#1F9B69' },
    },
    {
        id: 3,
        title: 'Mobile app refresh',
        owner: 'CD',
        ownerColor: '#7B61FF',
        status: 'Planning',
        variant: 'warning',
        bar: { startPercent: 0, widthPercent: 35, color: '#7B61FF' },
    },
    {
        id: 4,
        title: 'Identity & sync',
        owner: 'AR',
        ownerColor: '#4B5EF5',
        status: 'Review',
        variant: 'review',
        bar: { startPercent: 35, widthPercent: 65, color: '#DA972E' },
    },
    {
        id: 5,
        title: 'HR onboarding automation',
        owner: 'SL',
        ownerColor: '#00A884',
        status: 'Planning',
        variant: 'warning',
        bar: { startPercent: 70, widthPercent: 30, color: '#1F9B69' },
    },
];

export default function Timeline() {
    usePageTitle('Q4 product roadmap');
    const toast = useToast();
    const [milestoneModal, setMilestoneModal] = useState(false);
    const [form, setForm] = useState({ title: '', date: '', dependency: '' });

    function addMilestone(e) {
        e.preventDefault();
        toast.success(`Milestone "${form.title}" added to roadmap.`);
        setMilestoneModal(false);
        setForm({ title: '', date: '', dependency: '' });
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Q4 product roadmap</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Coordinate milestones, releases and cross-project dependencies.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => setMilestoneModal(true)}
                        className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                    >
                        + Milestone
                    </button>
                </div>
            </div>

            {/* Main Roadmap Timeline Card */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-6">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Roadmap timeline</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">Quarter view · October–December 2026</p>
                </div>

                {/* Gantt Header */}
                <div className="border-b border-[#F0F2F7] pb-3">
                    <div className="grid grid-cols-12 text-[11px] font-semibold uppercase tracking-wider text-[#8C96A8]">
                        <div className="col-span-4 pl-2">INITIATIVE / EPIC</div>
                        <div className="col-span-1 text-center">OWNER</div>
                        <div className="col-span-2 text-center">STATUS</div>
                        <div className="col-span-5 grid grid-cols-3 text-center">
                            <div>OCT</div>
                            <div>NOV</div>
                            <div>DEC</div>
                        </div>
                    </div>
                </div>

                {/* Gantt Rows */}
                <div className="divide-y divide-[#F0F2F7]">
                    {INITIATIVES.map((item) => (
                        <div key={item.id} className="grid grid-cols-12 items-center py-4 text-[13px] hover:bg-[#F8FAFD] transition-colors">
                            <div className="col-span-4 pl-2 font-medium text-[#171C2C]">{item.title}</div>
                            <div className="col-span-1 flex justify-center">
                                <div
                                    className="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-bold text-white shadow-xs"
                                    style={{ backgroundColor: item.ownerColor }}
                                >
                                    {item.owner}
                                </div>
                            </div>
                            <div className="col-span-2 flex justify-center">
                                <StatusPill variant={item.variant}>{item.status}</StatusPill>
                            </div>
                            <div className="col-span-5 px-4">
                                <div className="relative h-4 w-full rounded-full bg-[#F4F6FB]">
                                    <div
                                        className="absolute top-0 h-4 rounded-full shadow-xs transition-all"
                                        style={{
                                            left: `${item.bar.startPercent}%`,
                                            width: `${item.bar.widthPercent}%`,
                                            backgroundColor: item.bar.color,
                                        }}
                                    />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Bottom Milestone Callout */}
                <div className="mt-6 border-t border-[#F0F2F7] pt-4">
                    <div className="flex items-center gap-3 text-[13px]">
                        <span className="rounded-full bg-[#E9ECFF] px-3 py-0.5 text-[11px] font-semibold text-[#4B5EF5]">
                            Milestone
                        </span>
                        <span className="text-[#5A6478]">
                            Global Search GA · 02 Dec 2026 · Depends on identity sync and performance baseline
                        </span>
                    </div>
                </div>
            </div>

            {/* Add Milestone Modal */}
            <Modal open={milestoneModal} onClose={() => setMilestoneModal(false)} title="Add roadmap milestone" size="md">
                <form onSubmit={addMilestone} className="space-y-4">
                    <Input
                        label="Milestone title"
                        placeholder="e.g. Mobile App V2 Beta"
                        value={form.title}
                        onChange={(e) => setForm({ ...form, title: e.target.value })}
                        required
                    />
                    <Input
                        label="Target date"
                        type="date"
                        value={form.date}
                        onChange={(e) => setForm({ ...form, date: e.target.value })}
                        required
                    />
                    <Input
                        label="Dependencies"
                        placeholder="e.g. Identity sync, API V2 release"
                        value={form.dependency}
                        onChange={(e) => setForm({ ...form, dependency: e.target.value })}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setMilestoneModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit">Save milestone</Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
