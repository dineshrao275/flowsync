import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { Table, Th, Td } from '../../components/ui/Table';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

const CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];
const STATUSES = ['', 'available', 'assigned', 'maintenance', 'retired', 'lost'];

/**
 * The asset register: every item filterable by status, category and text,
 * with assign/return/repair drawers and the maintenance log.
 *
 * A manager screen end to end — reads ride the view permission, every
 * movement hides without manage (the backend 403s regardless). Returns
 * carry the condition back in: a damaged return parks the asset in
 * maintenance for triage rather than back on the shelf, which is why the
 * return form asks condition first and note second.
 */
export default function Assets() {
    usePageTitle('Assets');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const { can } = useAuth();
    const toast = useToast();

    const canManage = can('hrms.assets.manage');

    const [assets, setAssets] = useState(null);
    const [categories, setCategories] = useState([]);
    const [employees, setEmployees] = useState([]);
    const [filters, setFilters] = useState({ status: '', category_id: '', q: '' });
    const [error, setError] = useState(null);

    const [registerModal, setRegisterModal] = useState(false);
    const [registerForm, setRegisterForm] = useState({ name: '', category_id: '', brand: '', model: '', serial_number: '', condition: 'good' });
    const [registerErrors, setRegisterErrors] = useState({});

    const [assigning, setAssigning] = useState(null);
    const [assignForm, setAssignForm] = useState({ employee_id: '', condition_out: 'good' });
    const [assignErrors, setAssignErrors] = useState({});

    const [returning, setReturning] = useState(null);
    const [returnForm, setReturnForm] = useState({ condition_in: 'good', return_note: '' });
    const [returnErrors, setReturnErrors] = useState({});

    const [repairing, setRepairing] = useState(null);
    const [repairForm, setRepairForm] = useState({ type: 'repair', description: '', cost: '', performed_at: '', next_due_at: '' });
    const [repairErrors, setRepairErrors] = useState({});

    const [detail, setDetail] = useState(null);

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'Assets' }]);
    }, [setCrumbs]);

    const fail = useCallback(
        (message) => (err) => {
            if (err.response?.status === 403) {
                navigate('/403', { replace: true });
                return;
            }

            setError(message);
        },
        [navigate],
    );

    const load = useCallback(() => {
        setError(null);

        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));

        return Promise.all([
            api.get('/hrms/assets', { params }).then(({ data }) => setAssets(data.assets ?? [])),
            api.get('/hrms/assets/categories').then(({ data }) => setCategories(data.categories ?? [])),
        ]).catch(fail('Unable to load the register.'));
    }, [filters, fail]);

    useEffect(() => {
        load();

        api.get('/hrms/employees', { params: { per_page: 100 } })
            .then(({ data }) => setEmployees((data.employees ?? []).map((e) => ({ id: e.id, name: e.display_name ?? e.name }))))
            .catch(() => setEmployees([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters]);

    async function register(e) {
        e.preventDefault();
        setRegisterErrors({});

        try {
            await api.post('/hrms/assets', registerForm);
            toast.success('Asset registered.');
            setRegisterModal(false);
            setRegisterForm({ name: '', category_id: '', brand: '', model: '', serial_number: '', condition: 'good' });
            load();
        } catch (err) {
            setRegisterErrors(fieldErrors(err));
        }
    }

    function openAssign(asset) {
        setAssigning(asset);
        setAssignForm({ employee_id: '', condition_out: asset.condition ?? 'good' });
        setAssignErrors({});
    }

    async function assign(e) {
        e.preventDefault();
        setAssignErrors({});

        try {
            await api.post(`/hrms/assets/${assigning.id}/assign`, {
                ...assignForm,
                employee_id: Number(assignForm.employee_id),
            });
            toast.success('Asset assigned — the holder was told to acknowledge.');
            setAssigning(null);
            load();
        } catch (err) {
            setAssignErrors(fieldErrors(err));
        }
    }

    function openReturn(asset) {
        setReturning(asset);
        setReturnForm({ condition_in: asset.condition ?? 'good', return_note: '' });
        setReturnErrors({});
    }

    async function returnAsset(e) {
        e.preventDefault();
        setReturnErrors({});

        try {
            await api.post(`/hrms/assets/${returning.id}/return`, returnForm);
            toast.success('Asset returned.');
            setReturning(null);
            load();
        } catch (err) {
            setReturnErrors(fieldErrors(err));
        }
    }

    async function openRepair(e) {
        e.preventDefault();
        setRepairErrors({});

        try {
            await api.post(`/hrms/assets/${repairing.id}/maintenance`, repairForm);
            toast.success('Repair opened — the asset is parked in maintenance.');
            setRepairing(null);
            setRepairForm({ type: 'repair', description: '', cost: '', performed_at: '', next_due_at: '' });
            load();
        } catch (err) {
            setRepairErrors(fieldErrors(err));
        }
    }

    async function openDetail(asset) {
        try {
            const { data } = await api.get(`/hrms/assets/${asset.id}`);
            setDetail(data.asset);
        } catch {
            setError('That asset cannot be opened.');
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold text-gray-900">Assets</h2>
                    <p className="mt-0.5 text-sm text-gray-500">
                        {assets ? `${assets.length} item${assets.length === 1 ? '' : 's'}` : '—'}
                    </p>
                </div>
                {canManage && <Button onClick={() => setRegisterModal(true)}>Register an asset</Button>}
            </div>

            {error && <Alert>{error}</Alert>}

            <Card dense>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Select label="Status" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                        {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>)}
                    </Select>
                    <Select label="Category" value={filters.category_id} onChange={(e) => setFilters({ ...filters, category_id: e.target.value })}>
                        <option value="">All categories</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                    <Input label="Search" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} placeholder="Code, name, serial…" />
                </div>
            </Card>

            {!assets ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : assets.length === 0 ? (
                <EmptyState title="Register is empty" hint="Register the first laptop to start tracking." />
            ) : (
                <Card dense>
                    <Table>
                        <thead>
                            <tr><Th>Asset</Th><Th>Condition</Th><Th>Status</Th><Th>Holder</Th>{canManage && <Th><span className="sr-only">Actions</span></Th>}</tr>
                        </thead>
                        <tbody>
                            {assets.map((asset) => (
                                <tr key={asset.id}>
                                    <Td>
                                        <button type="button" className="font-medium text-indigo-600 hover:underline" onClick={() => openDetail(asset)}>
                                            {asset.asset_code}
                                        </button>
                                        <span className="block text-xs text-gray-400">{asset.name} · {asset.category?.name ?? ''}</span>
                                    </Td>
                                    <Td>{asset.condition}</Td>
                                    <Td>{asset.status}</Td>
                                    <Td>{asset.assignee?.name ?? '—'}</Td>
                                    {canManage && (
                                        <Td>
                                            <div className="flex flex-wrap gap-2">
                                                {asset.status === 'available' && <Button size="sm" variant="secondary" onClick={() => openAssign(asset)}>Assign</Button>}
                                                {asset.status === 'assigned' && <Button size="sm" variant="secondary" onClick={() => openReturn(asset)}>Return</Button>}
                                                {['available', 'assigned'].includes(asset.status) && <Button size="sm" variant="secondary" onClick={() => { setRepairing(asset); setRepairErrors({}); }}>Repair</Button>}
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}

            <Modal open={registerModal} onClose={() => setRegisterModal(false)} title="Register an asset">
                <form onSubmit={register} className="grid gap-3 sm:grid-cols-2">
                    <Input label="Name" value={registerForm.name} error={registerErrors.name} onChange={(e) => setRegisterForm({ ...registerForm, name: e.target.value })} required />
                    <Select label="Category" value={registerForm.category_id} error={registerErrors.category_id} onChange={(e) => setRegisterForm({ ...registerForm, category_id: e.target.value })} required>
                        <option value="">Select…</option>
                        {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                    <Input label="Brand" value={registerForm.brand} error={registerErrors.brand} onChange={(e) => setRegisterForm({ ...registerForm, brand: e.target.value })} />
                    <Input label="Model" value={registerForm.model} error={registerErrors.model} onChange={(e) => setRegisterForm({ ...registerForm, model: e.target.value })} />
                    <Input label="Serial number" value={registerForm.serial_number} error={registerErrors.serial_number} onChange={(e) => setRegisterForm({ ...registerForm, serial_number: e.target.value })} />
                    <Select label="Condition" value={registerForm.condition} error={registerErrors.condition} onChange={(e) => setRegisterForm({ ...registerForm, condition: e.target.value })}>
                        {CONDITIONS.filter((c) => c !== 'damaged').map((c) => <option key={c} value={c}>{c}</option>)}
                    </Select>
                    <div className="sm:col-span-2"><Button type="submit">Register</Button></div>
                </form>
            </Modal>

            <Modal open={!!assigning} onClose={() => setAssigning(null)} title={`Assign ${assigning?.asset_code ?? ''}`}>
                <form onSubmit={assign} className="grid gap-3">
                    <Select label="Person" value={assignForm.employee_id} error={assignErrors.employee_id} onChange={(e) => setAssignForm({ ...assignForm, employee_id: e.target.value })} required>
                        <option value="">Select…</option>
                        {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                    </Select>
                    <Select label="Condition out" value={assignForm.condition_out} error={assignErrors.condition_out} onChange={(e) => setAssignForm({ ...assignForm, condition_out: e.target.value })}>
                        {CONDITIONS.map((c) => <option key={c} value={c}>{c}</option>)}
                    </Select>
                    <div><Button type="submit">Hand over</Button></div>
                </form>
            </Modal>

            <Modal open={!!returning} onClose={() => setReturning(null)} title={`Take back ${returning?.asset_code ?? ''}`}>
                <form onSubmit={returnAsset} className="grid gap-3">
                    <Select label="Condition in" value={returnForm.condition_in} error={returnErrors.condition_in} onChange={(e) => setReturnForm({ ...returnForm, condition_in: e.target.value })}>
                        {CONDITIONS.map((c) => <option key={c} value={c}>{c}</option>)}
                    </Select>
                    <Input label="Return note" value={returnForm.return_note} error={returnErrors.return_note} onChange={(e) => setReturnForm({ ...returnForm, return_note: e.target.value })} />
                    <div><Button type="submit">Take back</Button></div>
                </form>
            </Modal>

            <Modal open={!!repairing} onClose={() => setRepairing(null)} title={`Repair ${repairing?.asset_code ?? ''}`}>
                <form onSubmit={openRepair} className="grid gap-3">
                    <Select label="Type" value={repairForm.type} error={repairErrors.type} onChange={(e) => setRepairForm({ ...repairForm, type: e.target.value })}>
                        {['repair', 'service', 'upgrade', 'inspection'].map((t) => <option key={t} value={t}>{t}</option>)}
                    </Select>
                    <Input label="Description" value={repairForm.description} error={repairErrors.description} onChange={(e) => setRepairForm({ ...repairForm, description: e.target.value })} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Cost" value={repairForm.cost} error={repairErrors.cost} onChange={(e) => setRepairForm({ ...repairForm, cost: e.target.value })} />
                        <Input label="Performed at" type="date" value={repairForm.performed_at} error={repairErrors.performed_at} onChange={(e) => setRepairForm({ ...repairForm, performed_at: e.target.value })} required />
                    </div>
                    <div><Button type="submit">Open repair</Button></div>
                </form>
            </Modal>

            <Modal open={!!detail} onClose={() => setDetail(null)} title={detail ? `${detail.asset_code} — ${detail.name}` : ''} size="lg">
                {detail && (
                    <div className="space-y-4 text-sm">
                        <p className="text-gray-500">
                            {detail.brand ?? ''} {detail.model ?? ''} · {detail.serial_number ?? 'no serial'} · {detail.condition} · {detail.status}
                        </p>
                        <div>
                            <h4 className="mb-1 font-semibold text-gray-900">Handover ledger</h4>
                            {(detail.assignments ?? []).length === 0 ? (
                                <p className="text-gray-500">Never handed over.</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {detail.assignments.map((row) => (
                                        <li key={row.id} className="py-1.5">
                                            {row.employee?.name ?? '—'} · out {row.condition_out}
                                            {row.acknowledged_at ? '' : ' · awaiting signature'}
                                            {row.returned_at ? ` · back ${row.condition_in ?? ''}` : ''}
                                            <span className="ml-2 text-xs text-gray-400">{row.status}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div>
                            <h4 className="mb-1 font-semibold text-gray-900">Maintenance log</h4>
                            {(detail.maintenance ?? []).length === 0 ? (
                                <p className="text-gray-500">No repairs on record.</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {detail.maintenance.map((row) => (
                                        <li key={row.id} className="py-1.5">
                                            {row.type} · {row.performed_at}{row.cost ? ` · ${row.cost}` : ''}
                                            <span className="block text-gray-600">{row.description}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
}
