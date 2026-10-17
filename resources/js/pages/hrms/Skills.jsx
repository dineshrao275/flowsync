import SkillGaps from '../../components/hrms/SkillGaps';
import SkillExpectations from '../../components/hrms/SkillExpectations';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm } from '../../components/ui/ConfirmDialog';
import usePageTitle from '../../hooks/usePageTitle';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Spinner from '../../components/ui/Spinner';
import Alert from '../../components/ui/Alert';
import { fieldClass } from '../../components/ui/fieldStyles';

const LEVELS = [1, 2, 3, 4, 5];
const CELL = ['', 'bg-gray-100 text-gray-700', 'bg-sky-100 text-sky-800', 'bg-blue-200 text-blue-900', 'bg-indigo-300 text-indigo-950', 'bg-indigo-500 text-white'];

function LevelSelect({ value, onChange, label, disabled }) {
    const { t } = useTranslation();
    return (
        <select aria-label={label} className={`${fieldClass} !w-auto !py-1.5`} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}>
            <option value="">{t('hrms.skills.noLevel')}</option>
            {LEVELS.map((l) => <option key={l} value={l}>{l} · {t(`hrms.skills.level.${l}`)}</option>)}
        </select>
    );
}

function MySkills({ skills }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [mine, setMine] = useState(null);
    const [draft, setDraft] = useState({});
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/skills/mine').then(({ data }) => { setMine(data.levels); setDraft({}); }).catch((e) => setError(e.response?.status === 404 ? t('hrms.skills.noRecord') : t('hrms.skills.loadFailed')));
    }, [t]);

    useEffect(load, [load]);

    async function save() {
        setSaving(true);
        setError(null);
        try {
            await api.put('/hrms/skills/mine', { skills: Object.entries(draft).map(([skill_id, level]) => ({ skill_id: Number(skill_id), level })) });
            toast.success(t('hrms.skills.saved'));
            load();
        } catch (err) {
            setError(fieldErrors(err).skills || t('hrms.skills.saveFailed'));
        } finally {
            setSaving(false);
        }
    }

    if (error && !mine) return <Alert>{error}</Alert>;
    if (!mine) return <div className="flex justify-center py-8"><Spinner /></div>;

    const byId = Object.fromEntries(mine.map((m) => [m.skill_id, m]));

    return (
        <div className="space-y-3">
            {error && <Alert>{error}</Alert>}
            <p className="text-sm text-gray-500">{t('hrms.skills.mineHint')}</p>
            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200">
                {skills.filter((s) => s.is_active).map((s) => {
                    const current = byId[s.id];
                    const value = s.id in draft ? draft[s.id] : current?.level ?? null;
                    const locked = current?.source === 'assessed';
                    return (
                        <li key={s.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                            <div>
                                <p className="text-sm font-medium text-gray-900">{s.name}</p>
                                {s.category && <p className="text-xs text-gray-500">{s.category}</p>}
                            </div>
                            <div className="flex items-center gap-2">
                                {locked && <Badge tone="info">{t('hrms.skills.assessed')}</Badge>}
                                <LevelSelect value={value} disabled={locked} label={s.name} onChange={(level) => setDraft((d) => ({ ...d, [s.id]: level }))} />
                            </div>
                        </li>
                    );
                })}
            </ul>
            <Button onClick={save} loading={saving} disabled={Object.keys(draft).length === 0}>{t('hrms.skills.save')}</Button>
        </div>
    );
}

function Matrix({ canManage }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [data, setData] = useState(null);
    const [q, setQ] = useState('');
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/hrms/skills/matrix', { params: q ? { q } : {} }).then(({ data: res }) => setData(res)).catch(() => setError(t('hrms.skills.loadFailed')));
    }, [q, t]);

    useEffect(() => {
        const id = setTimeout(load, 250);
        return () => clearTimeout(id);
    }, [load]);

    async function assess(employee, skill, level) {
        try {
            await api.put(`/hrms/employees/${employee.id}/skills`, { skills: [{ skill_id: skill.id, level }] });
            toast.success(t('hrms.skills.assessedToast'));
            load();
        } catch (err) {
            toast.error(fieldErrors(err).skills || t('hrms.skills.saveFailed'));
        }
    }

    if (error) return <Alert>{error}</Alert>;
    if (!data) return <div className="flex justify-center py-8"><Spinner /></div>;
    if (!data.skills.length) return <p className="py-8 text-center text-sm text-gray-500">{t('hrms.skills.emptyCatalog')}</p>;

    return (
        <div className="space-y-3">
            <div className="w-64"><Input label={t('hrms.skills.search')} compact value={q} onChange={(e) => setQ(e.target.value)} /></div>
            <div className="overflow-x-auto rounded-xl border border-line bg-white">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="bg-surface-sunken text-xs uppercase tracking-wide text-gray-500">
                            <th scope="col" className="sticky left-0 bg-surface-sunken px-3 py-2">{t('hrms.skills.employee')}</th>
                            {data.skills.map((s) => (
                                <th scope="col" key={s.id} className="px-3 py-2 text-center" title={s.category || ''}>
                                    {s.name}
                                    <div className="font-normal normal-case text-gray-400">{t('hrms.skills.coverage', { holders: data.coverage[s.id]?.holders ?? 0, expert: data.coverage[s.id]?.expert ?? 0 })}</div>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {data.employees.map((e) => (
                            <tr key={e.id} className="border-t border-gray-100">
                                <th scope="row" className="sticky left-0 bg-white px-3 py-2 text-left font-medium text-gray-900">{e.name}</th>
                                {data.skills.map((s) => {
                                    const cell = e.levels[s.id];
                                    return (
                                        <td key={s.id} className="px-2 py-1.5 text-center">
                                            {canManage ? (
                                                <select
                                                    aria-label={`${e.name} · ${s.name}`}
                                                    className={`rounded px-1.5 py-0.5 text-xs ${cell ? CELL[cell.level] : 'bg-white text-gray-400'} ${cell?.source === 'self' ? 'italic' : 'font-semibold'}`}
                                                    value={cell?.level ?? ''}
                                                    onChange={(ev) => assess(e, s, ev.target.value === '' ? null : Number(ev.target.value))}
                                                >
                                                    <option value="">·</option>
                                                    {LEVELS.map((l) => <option key={l} value={l}>{l}</option>)}
                                                </select>
                                            ) : (
                                                <span className={`inline-block min-w-6 rounded px-1.5 py-0.5 text-xs ${cell ? CELL[cell.level] : 'text-gray-300'}`}>{cell?.level ?? '·'}</span>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-xs text-gray-500">{t('hrms.skills.legend')}</p>
        </div>
    );
}

function Catalog({ skills, reload }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [form, setForm] = useState({ name: '', category: '' });
    const [errors, setErrors] = useState({});

    async function add(e) {
        e.preventDefault();
        setErrors({});
        try {
            await api.post('/hrms/skills', { name: form.name.trim(), category: form.category.trim() || null });
            setForm({ name: '', category: '' });
            reload();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function toggle(s) {
        await api.put(`/hrms/skills/${s.id}`, { is_active: !s.is_active }).catch(() => toast.error(t('hrms.skills.saveFailed')));
        reload();
    }

    async function remove(s) {
        if (!(await confirm({ title: t('hrms.skills.deleteTitle'), message: t('hrms.skills.deleteMessage', { name: s.name }), variant: 'danger' }))) return;
        try {
            await api.delete(`/hrms/skills/${s.id}`);
            reload();
        } catch (err) {
            toast.error(fieldErrors(err).form || t('hrms.skills.saveFailed'));
        }
    }

    return (
        <div className="space-y-4">
            <form onSubmit={add} className="flex flex-wrap items-end gap-3">
                <div className="w-64"><Input label={t('hrms.skills.name')} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={errors.name} required maxLength={120} /></div>
                <div className="w-52"><Input label={t('hrms.skills.category')} value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} maxLength={80} /></div>
                <Button type="submit" disabled={!form.name.trim()}>{t('hrms.skills.add')}</Button>
            </form>
            <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200">
                {skills.map((s) => (
                    <li key={s.id} className={`flex items-center justify-between gap-3 px-4 py-2.5 ${s.is_active ? '' : 'opacity-60'}`}>
                        <div>
                            <p className="text-sm font-medium text-gray-900">{s.name} {!s.is_active && <Badge tone="neutral">{t('hrms.skills.archived')}</Badge>}</p>
                            <p className="text-xs text-gray-500">{[s.category, t('hrms.skills.holders', { count: s.holders })].filter(Boolean).join(' · ')}</p>
                        </div>
                        <div className="flex gap-2">
                            <Button size="sm" variant="ghost" onClick={() => toggle(s)}>{s.is_active ? t('hrms.skills.archive') : t('hrms.skills.restore')}</Button>
                            <Button size="sm" variant="ghost" disabled={s.holders > 0} onClick={() => remove(s)}>{t('hrms.skills.delete')}</Button>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Skills: your own levels, the team matrix, and (for talent managers) the catalogue. */
export default function Skills() {
    const { t } = useTranslation();
    usePageTitle(t('hrms.skills.pageTitle'));
    const { can } = useAuth();
    const manage = can('hrms.talent.manage');
    const [skills, setSkills] = useState(null);
    const tabs = useMemo(() => ['mine', 'gaps', 'matrix', ...(manage ? ['expectations', 'catalog'] : [])], [manage]);
    const [tab, setTab] = useState('mine');

    const load = useCallback(() => {
        api.get('/hrms/skills').then(({ data }) => setSkills(data.skills)).catch(() => setSkills([]));
    }, []);

    useEffect(load, [load]);

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('hrms.skills.pageTitle')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('hrms.skills.subtitle')}</p>
            </div>
            <div role="tablist" className="flex gap-1 border-b border-gray-200">
                {tabs.map((k) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
                        className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${tab === k ? 'border-[var(--accent)] text-[var(--accent)]' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>
                        {t(`hrms.skills.tab.${k}`)}
                    </button>
                ))}
            </div>
            <Card>
                {!skills ? <div className="flex justify-center py-8"><Spinner /></div>
                    : tab === 'mine' ? <MySkills skills={skills} />
                        : tab === 'gaps' ? <SkillGaps />
                            : tab === 'matrix' ? <Matrix canManage={manage} />
                                : tab === 'expectations' ? <SkillExpectations skills={skills} />
                                    : <Catalog skills={skills} reload={load} />}
            </Card>
        </div>
    );
}
