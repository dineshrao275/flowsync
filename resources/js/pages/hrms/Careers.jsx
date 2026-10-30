import { useCallback, useEffect, useMemo, useState } from 'react';
import PersonSelect from '../../components/ui/PersonSelect';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const RISK_TONE = { low: 'success', medium: 'warning', high: 'danger' };

function Paths({ designations, manage }) {
    const { t } = useTranslation();
    const [paths, setPaths] = useState(null);
    const [form, setForm] = useState({ from_designation_id: '', to_designation_id: '', note: '' });
    const [error, setError] = useState(null);

    const load = useCallback(() => api.get('/hrms/career-paths').then(({ data }) => setPaths(data.paths)).catch(() => setPaths([])), []);
    useEffect(() => { load(); }, [load]);

    async function add(e) {
        e.preventDefault();
        setError(null);
        try {
            await api.post('/hrms/career-paths', { ...form, from_designation_id: Number(form.from_designation_id), to_designation_id: Number(form.to_designation_id), note: form.note || null });
            setForm({ from_designation_id: '', to_designation_id: '', note: '' });
            load();
        } catch (err) {
            const fe = fieldErrors(err);
            setError(fe.form || fe.to_designation_id || fe.from_designation_id || t('hrms.careers.failed'));
        }
    }

    if (!paths) return <ContentSkeleton size="md" />;

    return (
        <div className="space-y-4">
            {paths.length === 0 ? <p className="py-4 text-center text-sm text-gray-500">{t('hrms.careers.noPaths')}</p> : (
                <ul className="divide-y divide-gray-100">
                    {paths.map((p) => (
                        <li key={p.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                            <span><span className="font-medium text-gray-900">{p.from}</span> → <span className="font-medium text-gray-900">{p.to}</span>{p.note ? <span className="ml-2 text-gray-500">{p.note}</span> : null}</span>
                            {manage && <Button size="sm" variant="danger" onClick={async () => { await api.delete(`/hrms/career-paths/${p.id}`); load(); }}>{t('hrms.common.remove')}</Button>}
                        </li>
                    ))}
                </ul>
            )}
            {manage && (
                <form onSubmit={add} className="grid gap-3 border-t border-gray-100 pt-4 sm:grid-cols-4">
                    {['from_designation_id', 'to_designation_id'].map((k) => (
                        <div key={k}>
                            <label htmlFor={`cp-${k}`} className="mb-1.5 block text-sm font-medium text-gray-700">{t(`hrms.careers.${k === 'from_designation_id' ? 'from' : 'to'}`)}</label>
                            <select id={`cp-${k}`} className={fieldClass} value={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.value })}>
                                <option value="">—</option>
                                {designations.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select>
                        </div>
                    ))}
                    <Input label={t('hrms.careers.note')} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
                    <div className="flex items-end"><Button type="submit" disabled={!form.from_designation_id || !form.to_designation_id}>{t('hrms.careers.addPath')}</Button></div>
                    {error && <div className="sm:col-span-4"><Alert>{error}</Alert></div>}
                </form>
            )}
        </div>
    );
}

function Succession({ people }) {
    const { t } = useTranslation();
    const [positions, setPositions] = useState(null);
    const [form, setForm] = useState({ title: '', incumbent_employee_id: '', risk: 'medium' });
    const [cand, setCand] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => api.get('/hrms/succession').then(({ data }) => setPositions(data.positions)).catch(() => setPositions([])), []);
    useEffect(() => { load(); }, [load]);

    async function addPosition(e) {
        e.preventDefault();
        await api.post('/hrms/succession', { ...form, incumbent_employee_id: form.incumbent_employee_id ? Number(form.incumbent_employee_id) : null });
        setForm({ title: '', incumbent_employee_id: '', risk: 'medium' });
        load();
    }

    async function addCandidate(position) {
        const c = cand[position.id] || {};
        setError(null);
        try {
            await api.put(`/hrms/succession/${position.id}/candidates`, { employee_id: Number(c.employee_id), readiness: c.readiness || 'one_two_years' });
            setCand((s) => ({ ...s, [position.id]: {} }));
            load();
        } catch (err) {
            setError(fieldErrors(err).employee_id || t('hrms.careers.failed'));
        }
    }

    if (!positions) return <ContentSkeleton size="md" />;

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-500">{t('hrms.careers.confidential')}</p>
            {error && <Alert>{error}</Alert>}
            {positions.length === 0 && <p className="py-4 text-center text-sm text-gray-500">{t('hrms.careers.noPositions')}</p>}
            {positions.map((p) => (
                <div key={p.id} className="rounded-lg border border-gray-200 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-medium text-gray-900">{p.title} <Badge tone={RISK_TONE[p.risk]}>{t(`hrms.careers.risk.${p.risk}`)}</Badge>{p.incumbent ? <span className="ml-2 text-sm font-normal text-gray-500">{t('hrms.careers.heldBy', { name: p.incumbent.name })}</span> : null}</p>
                        <Button size="sm" variant="danger" onClick={async () => { await api.delete(`/hrms/succession/${p.id}`); load(); }}>{t('hrms.common.remove')}</Button>
                    </div>
                    <ul className="mt-2 space-y-1 text-sm">
                        {p.candidates.map((c) => (
                            <li key={c.id} className="flex items-center justify-between gap-2">
                                <span>{c.name} · <span className="text-gray-500">{t(`hrms.careers.readiness.${c.readiness}`)}</span></span>
                                <button type="button" className="text-xs text-gray-400 hover:text-red-600" onClick={async () => { await api.delete(`/hrms/succession/candidates/${c.id}`); load(); }}>{t('hrms.common.remove')}</button>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <PersonSelect aria-label={t('hrms.careers.successor')} value={cand[p.id]?.employee_id || ''} onChange={(e) => setCand((s) => ({ ...s, [p.id]: { ...s[p.id], employee_id: e.target.value } }))} placeholder={t('hrms.careers.successor')} people={people} />
                        <select aria-label={t('hrms.careers.readinessLabel')} className="rounded-md border border-gray-300 px-2 py-1 text-sm" value={cand[p.id]?.readiness || 'one_two_years'} onChange={(e) => setCand((s) => ({ ...s, [p.id]: { ...s[p.id], readiness: e.target.value } }))}>
                            {['now', 'one_two_years', 'three_plus'].map((r) => <option key={r} value={r}>{t(`hrms.careers.readiness.${r}`)}</option>)}
                        </select>
                        <Button size="sm" onClick={() => addCandidate(p)} disabled={!cand[p.id]?.employee_id}>{t('hrms.careers.addSuccessor')}</Button>
                    </div>
                </div>
            ))}
            <form onSubmit={addPosition} className="grid gap-3 border-t border-gray-100 pt-4 sm:grid-cols-4">
                <Input label={t('hrms.careers.positionTitle')} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required />
                <div>
                    <label htmlFor="sp-inc" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.careers.incumbent')}</label>
                    <PersonSelect id="sp-inc" value={form.incumbent_employee_id} onChange={(e) => setForm({ ...form, incumbent_employee_id: e.target.value })} placeholder="—" people={people} />
                </div>
                <div>
                    <label htmlFor="sp-risk" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.careers.riskLabel')}</label>
                    <select id="sp-risk" className={fieldClass} value={form.risk} onChange={(e) => setForm({ ...form, risk: e.target.value })}>
                        {['low', 'medium', 'high'].map((r) => <option key={r} value={r}>{t(`hrms.careers.risk.${r}`)}</option>)}
                    </select>
                </div>
                <div className="flex items-end"><Button type="submit" disabled={!form.title.trim()}>{t('hrms.careers.addPosition')}</Button></div>
            </form>
        </div>
    );
}

/** Career paths for everyone; succession plans (confidential) for talent managers. */
export default function Careers() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.careers.pageTitle'));
    const { can } = useAuth();
    const manage = can('hrms.talent.manage');
    const [tab, setTab] = useState('paths');
    const [designations, setDesignations] = useState([]);
    const [people, setPeople] = useState([]);
    const tabs = useMemo(() => ['paths', ...(manage ? ['succession'] : [])], [manage]);

    useEffect(() => {
        api.get('/hrms/employees', { params: { per_page: 250 } }).then(({ data }) => { setDesignations(data.filters?.designations || []); setPeople(data.employees || []); }).catch(() => {});
    }, []);

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('hrms.careers.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('hrms.careers.subtitle')}</p>
            </div>
            <div role="tablist" className="flex gap-1 border-b border-gray-200">
                {tabs.map((k) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
                        className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${tab === k ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>
                        {t(`hrms.careers.tab.${k}`)}
                    </button>
                ))}
            </div>
            <Card>{tab === 'paths' ? <Paths designations={designations} manage={manage} /> : <Succession people={people} />}</Card>
        </div>
    );
}
