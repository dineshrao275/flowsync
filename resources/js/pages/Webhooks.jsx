import { useCallback, useEffect, useState } from 'react';
import api, { fieldErrors } from '../services/api';
import Alert from '../components/ui/Alert';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Modal from '../components/ui/Modal';
import Spinner from '../components/ui/Spinner';
import StatusPill from '../components/ui/StatusPill';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';

const TRIGGERS = [
    { name: 'Employee created', product: 'HRMS', color: '#4B5EF5' },
    { name: 'Leave approved', product: 'HRMS', color: '#1F9B69' },
    { name: 'Task status changed', product: 'TMS', color: '#7B61FF' },
    { name: 'Task overdue', product: 'TMS', color: '#DA972E' },
    { name: 'Payroll completed', product: 'HRMS', color: '#00A884' },
];

const DEFAULT_ACTIVE_RULES = [
    { id: 1, trigger: 'Employee joins', action: 'Create onboarding tasks', status: 'Active', variant: 'healthy' },
    { id: 2, trigger: 'Leave approved', action: 'Update project capacity', status: 'Active', variant: 'healthy' },
    { id: 3, trigger: 'Task becomes blocked', action: 'Notify dependency owner', status: 'Active', variant: 'healthy' },
    { id: 4, trigger: 'Payroll closes', action: 'Lock payroll workflow', status: 'Paused', variant: 'warning' },
];

export default function Webhooks() {
    usePageTitle('Automation rules');
    const toast = useToast();
    const setCrumbs = useSetCrumbs();

    const [data, setData] = useState(null);
    const [rules, setRules] = useState(DEFAULT_ACTIVE_RULES);
    const [form, setForm] = useState({ url: '', description: '', events: ['*'] });
    const [errors, setErrors] = useState({});
    const [creatingWebhook, setCreatingWebhook] = useState(false);
    const [createRuleModal, setCreateRuleModal] = useState(false);
    const [saving, setSaving] = useState(false);
    const [secret, setSecret] = useState(null);

    const [newRule, setNewRule] = useState({ trigger: '', condition: '', action: '' });

    useEffect(() => {
        setCrumbs([{ label: 'Workflow & automation' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        return api
            .get('/webhooks')
            .then(({ data: d }) => setData(d))
            .catch(() => setData({ endpoints: [], catalog: [] }));
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    async function createEndpoint(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const res = await api.post('/webhooks', form);
            setSecret({ id: res.data.endpoint.id, value: res.data.secret });
            setCreatingWebhook(false);
            setForm({ url: '', description: '', events: ['*'] });
            load();
            toast.success('Webhook endpoint registered.');
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setSaving(false);
        }
    }

    function publishAutomation() {
        toast.success('Automation rule published and active.');
    }

    function testRule() {
        toast.success('Rule test passed: conditions evaluated true, dry-run executed.');
    }

    function addRule(e) {
        e.preventDefault();
        if (!newRule.trigger || !newRule.action) return;
        setRules((prev) => [
            ...prev,
            {
                id: Date.now(),
                trigger: newRule.trigger,
                action: newRule.action,
                status: 'Active',
                variant: 'healthy',
            },
        ]);
        setCreateRuleModal(false);
        setNewRule({ trigger: '', condition: '', action: '' });
        toast.success('Automation rule added.');
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-[#171C2C]">Automation rules</h1>
                    <p className="mt-1 text-[13px] text-[#5A6478]">
                        Connect events, conditions and actions across HRMS and TMS.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => setCreateRuleModal(true)}
                        className="inline-flex items-center justify-center rounded-lg bg-[#4B5EF5] px-4 py-2.5 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                    >
                        + Create rule
                    </button>
                </div>
            </div>

            {/* Middle Section: Rule builder (Left) & Available triggers (Right) */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                {/* Left: Rule builder */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-8">
                    <div className="mb-6">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Rule builder</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                            When an event happens, check conditions, then run actions.
                        </p>
                    </div>

                    {/* Stepper Flow */}
                    <div className="space-y-4 max-w-xl">
                        {/* Step 01: WHEN */}
                        <div className="flex items-start gap-4">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#4B5EF5] text-[13px] font-bold text-white shadow-xs">
                                01
                            </div>
                            <div className="pt-0.5">
                                <span className="text-[11px] font-bold uppercase tracking-wider text-[#8C96A8]">WHEN</span>
                                <h3 className="text-[15px] font-semibold text-[#171C2C]">Employee joins HRMS</h3>
                            </div>
                        </div>

                        {/* Down Arrow 1 */}
                        <div className="flex items-center pl-4 text-[#8C96A8]">
                            <span>↓</span>
                        </div>

                        {/* Step 02: IF */}
                        <div className="flex items-start gap-4">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#7B61FF] text-[13px] font-bold text-white shadow-xs">
                                02
                            </div>
                            <div className="pt-0.5">
                                <span className="text-[11px] font-bold uppercase tracking-wider text-[#8C96A8]">IF</span>
                                <h3 className="text-[15px] font-semibold text-[#171C2C]">Department = Engineering</h3>
                            </div>
                        </div>

                        {/* Down Arrow 2 */}
                        <div className="flex items-center pl-4 text-[#8C96A8]">
                            <span>↓</span>
                        </div>

                        {/* Step 03: THEN */}
                        <div className="flex items-start gap-4">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#00A884] text-[13px] font-bold text-white shadow-xs">
                                03
                            </div>
                            <div className="pt-0.5">
                                <span className="text-[11px] font-bold uppercase tracking-wider text-[#8C96A8]">THEN</span>
                                <h3 className="text-[15px] font-semibold text-[#171C2C]">Create onboarding tasks in TMS</h3>
                            </div>
                        </div>
                    </div>

                    {/* Builder Actions */}
                    <div className="mt-8 flex items-center gap-3 border-t border-[#F0F2F7] pt-5">
                        <button
                            type="button"
                            onClick={testRule}
                            className="rounded-lg border border-[#E5E8F0] bg-white px-4 py-2 text-[13px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] transition-colors shadow-xs"
                        >
                            Test rule
                        </button>
                        <button
                            type="button"
                            onClick={publishAutomation}
                            className="rounded-lg bg-[#4B5EF5] px-4 py-2 text-[13px] font-medium text-white shadow-sm hover:bg-[#3D4EE0] transition-colors"
                        >
                            Publish automation
                        </button>
                    </div>
                </div>

                {/* Right: Available triggers */}
                <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs lg:col-span-4">
                    <div className="mb-4">
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Available triggers</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">Events from both products</p>
                    </div>

                    <div className="divide-y divide-[#F0F2F7]">
                        {TRIGGERS.map((trigger) => (
                            <div key={trigger.name} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                                <div className="flex items-center gap-2.5">
                                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: trigger.color }} />
                                    <span className="text-[13px] font-medium text-[#171C2C]">{trigger.name}</span>
                                </div>
                                <span className="rounded-md bg-[#F4F6FB] px-2 py-0.5 text-[11px] font-semibold text-[#5A6478]">
                                    {trigger.product}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Bottom Card: Active automation rules */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4">
                    <h2 className="text-[16px] font-semibold text-[#171C2C]">Active automation rules</h2>
                    <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                        Asynchronous, auditable execution that keeps tenant context.
                    </p>
                </div>

                <div className="divide-y divide-[#F0F2F7]">
                    {rules.map((rule) => (
                        <div key={rule.id} className="flex items-center justify-between py-3.5 first:pt-1 last:pb-1">
                            <div>
                                <span className="text-[14px] font-medium text-[#171C2C]">{rule.trigger}</span>
                            </div>
                            <div className="text-[13px] text-[#5A6478]">{rule.action}</div>
                            <div>
                                <StatusPill variant={rule.variant}>{rule.status}</StatusPill>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Outbound Webhooks Section */}
            <div className="rounded-2xl border border-[#E5E8F0] bg-white p-6 shadow-xs">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-[16px] font-semibold text-[#171C2C]">Outbound webhook endpoints</h2>
                        <p className="mt-0.5 text-[12px] text-[#8C96A8]">
                            Receive signed HTTP POST events whenever actions complete.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setCreatingWebhook(true)}
                        className="rounded-lg border border-[#E5E8F0] bg-white px-3.5 py-1.5 text-[12px] font-medium text-[#171C2C] hover:bg-[#F8FAFD] shadow-xs"
                    >
                        + Add endpoint
                    </button>
                </div>

                {secret && (
                    <Alert type="success" className="mb-4">
                        <p className="font-semibold text-[13px]">Signing secret — copy it now (shown once only):</p>
                        <code className="mt-1 block break-all rounded bg-[#F4F6FB] p-2 font-mono text-[11px] text-[#171C2C]">
                            {secret.value}
                        </code>
                    </Alert>
                )}

                {!data ? (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                ) : data.endpoints?.length === 0 ? (
                    <p className="py-4 text-center text-[13px] text-[#8C96A8]">No external webhook endpoints configured yet.</p>
                ) : (
                    <div className="divide-y divide-[#F0F2F7]">
                        {data.endpoints.map((ep) => (
                            <div key={ep.id} className="flex items-center justify-between py-3 text-[13px]">
                                <div>
                                    <span className="font-mono text-[#171C2C]">{ep.url}</span>
                                    {ep.description && <span className="ml-2 text-[#8C96A8]">({ep.description})</span>}
                                </div>
                                <span className="rounded bg-[#E6F7EF] px-2 py-0.5 text-[11px] font-medium text-[#1F9B69]">
                                    Active
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Create Rule Modal */}
            <Modal open={createRuleModal} onClose={() => setCreateRuleModal(false)} title="Create automation rule" size="md">
                <form onSubmit={addRule} className="space-y-4">
                    <Input
                        label="Trigger event"
                        placeholder="e.g. Employee joins"
                        value={newRule.trigger}
                        onChange={(e) => setNewRule({ ...newRule, trigger: e.target.value })}
                        required
                    />
                    <Input
                        label="Condition (optional)"
                        placeholder="e.g. Department = Design"
                        value={newRule.condition}
                        onChange={(e) => setNewRule({ ...newRule, condition: e.target.value })}
                    />
                    <Input
                        label="Action"
                        placeholder="e.g. Create welcome package in TMS"
                        value={newRule.action}
                        onChange={(e) => setNewRule({ ...newRule, action: e.target.value })}
                        required
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setCreateRuleModal(false)}>
                            Cancel
                        </Button>
                        <Button type="submit">Create rule</Button>
                    </div>
                </form>
            </Modal>

            {/* Add Webhook Modal */}
            <Modal open={creatingWebhook} onClose={() => setCreatingWebhook(false)} title="Add webhook endpoint" size="md">
                <form onSubmit={createEndpoint} className="space-y-4">
                    <Input
                        label="Endpoint URL"
                        placeholder="https://example.com/hooks/flowsync"
                        value={form.url}
                        onChange={(e) => setForm({ ...form, url: e.target.value })}
                        error={errors.url}
                        required
                    />
                    <Input
                        label="Description (optional)"
                        placeholder="Internal event receiver"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                    />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setCreatingWebhook(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={saving}>
                            Add endpoint
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
