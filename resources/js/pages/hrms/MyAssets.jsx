import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Spinner from '../../components/ui/Spinner';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';

/**
 * The caller's open handovers: acknowledge the receipt, and report damage
 * when hardware comes back hurt.
 *
 * Self-scoped on purpose — this page rides the module alone like My files,
 * because it only ever shows the caller's rows. Acknowledging is the
 * holder's signature (the backend refuses anyone else's), and reporting
 * damage takes the item back as damaged — which parks it in maintenance
 * for triage rather than back on the shelf.
 */
export default function MyAssets() {
    usePageTitle('My assets');
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const [damaging, setDamaging] = useState(null);
    const [damageNote, setDamageNote] = useState('');
    const [damageErrors, setDamageErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: 'My assets' }]);
    }, [setCrumbs]);

    const load = useCallback(() => {
        setError(null);

        return api
            .get('/hrms/my/assets')
            .then(({ data: response }) => setData(response))
            .catch((err) => {
                if (err.response?.status === 403) {
                    navigate('/403', { replace: true });
                    return;
                }

                setError('Unable to load your hardware.');
            });
    }, [navigate]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function acknowledge(assignment) {
        try {
            await api.post(`/hrms/assets/assignments/${assignment.id}/acknowledge`, {});
            toast.success('Receipt recorded.');
            load();
        } catch {
            setError('That handover cannot be acknowledged.');
        }
    }

    async function reportDamage(e) {
        e.preventDefault();
        setDamageErrors({});

        try {
            // Damage goes back through the manager's return with the damaged
            // condition — the register parks it in maintenance, and HR
            // triages from there. There is no "keep a broken laptop" path.
            await api.post(`/hrms/assets/${damaging.asset_id}/return`, {
                condition_in: 'damaged',
                return_note: damageNote || null,
            });
            toast.success('Damage reported — the item goes to triage.');
            setDamaging(null);
            setDamageNote('');
            load();
        } catch (err) {
            setDamageErrors(err.response?.data?.errors ?? { form: ['That item cannot be returned.'] });
        }
    }

    const rows = data?.assignments ?? null;

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">My assets</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {rows ? `${rows.length} open handover${rows.length === 1 ? '' : 's'}` : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {!rows ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : rows.length === 0 ? (
                <EmptyState title="Nothing checked out" hint="Hardware assigned to you appears here for acknowledgement." />
            ) : (
                <Card dense>
                    <ul className="divide-y divide-gray-100">
                        {rows.map((assignment) => (
                            <li key={assignment.id} className="py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <span className="font-medium text-gray-900">
                                            {assignment.asset?.asset_code} · {assignment.asset?.name}
                                        </span>
                                        <p className="text-sm text-gray-500">
                                            Out {assignment.condition_out}
                                            {assignment.acknowledged_at ? '' : ' · awaiting your signature'}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        {!assignment.acknowledged_at && (
                                            <Button size="sm" onClick={() => acknowledge(assignment)}>Acknowledge</Button>
                                        )}
                                        <Button size="sm" variant="secondary" onClick={() => { setDamaging(assignment); setDamageNote(''); setDamageErrors({}); }}>
                                            Report damage
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Modal open={!!damaging} onClose={() => setDamaging(null)} title={`Report damage — ${damaging?.asset?.asset_code ?? ''}`}>
                <form onSubmit={reportDamage} className="grid gap-3">
                    <Select label="Condition in" value="damaged" disabled>
                        <option value="damaged">damaged</option>
                    </Select>
                    <Input label="What happened?" value={damageNote} error={damageErrors.return_note} onChange={(e) => setDamageNote(e.target.value)} />
                    <div><Button type="submit" variant="danger">Send to triage</Button></div>
                </form>
            </Modal>
        </div>
    );
}
