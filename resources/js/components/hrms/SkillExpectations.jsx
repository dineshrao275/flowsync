import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import { useToast } from '../../context/ToastContext';
import Button from '../ui/Button';
import Spinner from '../ui/Spinner';
import { fieldClass } from '../ui/fieldStyles';

/** Talent managers: pick a designation, then the level it is expected to hold in each skill. */
export default function SkillExpectations({ skills }) {
    const { t } = useTranslation();
    const toast = useToast();
    const [designations, setDesignations] = useState(null);
    const [expectations, setExpectations] = useState({});
    const [designation, setDesignation] = useState('');
    const [levels, setLevels] = useState({});

    const load = useCallback(() => api.get('/hrms/skill-expectations').then(({ data }) => setExpectations(data.expectations || {})), []);

    useEffect(() => {
        api.get('/hrms/employees', { params: { per_page: 1 } }).then(({ data }) => setDesignations(data.filters?.designations || [])).catch(() => setDesignations([]));
        load();
    }, [load]);

    function pick(id) {
        setDesignation(id);
        setLevels(Object.fromEntries((expectations[id] || []).map((e) => [e.skill_id, String(e.level)])));
    }

    async function save() {
        const payload = Object.entries(levels).filter(([, v]) => v).map(([skill_id, level]) => ({ skill_id: Number(skill_id), level: Number(level) }));
        await api.put(`/hrms/skill-expectations/${designation}`, { skills: payload });
        toast.success(t('hrms.skills.expectations.saved'));
        load();
    }

    if (!designations) return <div className="flex justify-center py-8"><Spinner /></div>;

    return (
        <div className="space-y-4">
            <div className="max-w-sm">
                <label htmlFor="exp-des" className="mb-1.5 block text-sm font-medium text-gray-700">{t('hrms.skills.expectations.designation')}</label>
                <select id="exp-des" className={fieldClass} value={designation} onChange={(e) => pick(e.target.value)}>
                    <option value="">—</option>
                    {designations.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
            </div>
            {designation && (
                <>
                    <ul className="divide-y divide-gray-100">
                        {skills.filter((s) => s.is_active !== false).map((s) => (
                            <li key={s.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="text-gray-800">{s.name}</span>
                                <select aria-label={s.name} className="w-28 rounded-md border border-gray-300 px-2 py-1 text-sm" value={levels[s.id] || ''} onChange={(e) => setLevels((l) => ({ ...l, [s.id]: e.target.value }))}>
                                    <option value="">{t('hrms.skills.expectations.none')}</option>
                                    {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                                </select>
                            </li>
                        ))}
                    </ul>
                    <Button onClick={save}>{t('hrms.skills.expectations.save')}</Button>
                </>
            )}
        </div>
    );
}
