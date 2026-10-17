import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../services/api';
import { useToast } from '../context/ToastContext';
import usePageTitle from '../hooks/usePageTitle';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Input from '../components/ui/Input';
import Alert from '../components/ui/Alert';
import Spinner from '../components/ui/Spinner';
import { Table, Th, Td } from '../components/ui/Table';
import { fieldClass } from '../components/ui/fieldStyles';

/** Build a report from a dataset and columns, preview it, save it, and have it emailed on a schedule. */
export default function ReportBuilder() {
    const { t } = useTranslation();
    usePageTitle(t('dashboard.reports.builder.title'));
    const toast = useToast();
    const [data, setData] = useState(null);
    const [dataset, setDataset] = useState('');
    const [columns, setColumns] = useState([]);
    const [filters, setFilters] = useState({});
    const [name, setName] = useState('');
    const [schedule, setSchedule] = useState('none');
    const [preview, setPreview] = useState(null);
    const [errors, setErrors] = useState({});
    const [error, setError] = useState(null);

    const load = useCallback(() => {
        api.get('/report-builder').then(({ data: res }) => setData(res)).catch(() => setError(t('dashboard.reports.builder.loadFailed')));
    }, [t]);
    useEffect(() => { load(); }, [load]);

    const def = data?.catalog?.[dataset];
    const body = () => ({ dataset, columns, filters: Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== false)) });

    function pick(ds) {
        setDataset(ds);
        setColumns(ds ? Object.keys(data.catalog[ds].columns).slice(0, 5) : []);
        setFilters({});
        setPreview(null);
    }

    async function run() {
        setErrors({});
        try {
            const { data: res } = await api.post('/report-builder/preview', body());
            setPreview(res);
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function save() {
        setErrors({});
        try {
            await api.post('/report-builder', { ...body(), name, schedule });
            toast.success(t('dashboard.reports.builder.saved'));
            setName('');
            load();
        } catch (err) {
            setErrors(fieldErrors(err));
        }
    }

    async function download(r) {
        const res = await api.get(`/report-builder/${r.id}/export`, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${r.name}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }

    if (error) return <Alert>{error}</Alert>;
    if (!data) return <div className="flex justify-center py-12"><Spinner /></div>;

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold text-gray-900">{t('dashboard.reports.builder.title')}</h2>
                <p className="mt-1 text-sm text-gray-500">{t('dashboard.reports.builder.subtitle')}</p>
            </div>

            <Card title={t('dashboard.reports.builder.build')}>
                <div className="space-y-4">
                    <div className="max-w-xs">
                        <label htmlFor="rb-ds" className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.reports.builder.dataset')}</label>
                        <select id="rb-ds" className={fieldClass} value={dataset} onChange={(e) => pick(e.target.value)}>
                            <option value="">—</option>
                            {Object.entries(data.catalog).filter(([, d]) => d.available).map(([k, d]) => <option key={k} value={k}>{d.label}</option>)}
                        </select>
                    </div>
                    {def && (
                        <>
                            <fieldset>
                                <legend className="mb-1.5 text-sm font-medium text-gray-700">{t('dashboard.reports.builder.columns')}</legend>
                                <div className="flex flex-wrap gap-x-4 gap-y-1">
                                    {Object.entries(def.columns).map(([k, label]) => (
                                        <label key={k} className="flex items-center gap-1.5 text-sm text-gray-700">
                                            <input type="checkbox" checked={columns.includes(k)} onChange={() => setColumns((c) => (c.includes(k) ? c.filter((x) => x !== k) : [...c, k]))} /> {label}
                                        </label>
                                    ))}
                                </div>
                                {errors.columns && <p className="mt-1 text-xs text-red-600">{errors.columns}</p>}
                            </fieldset>
                            <div className="grid gap-3 sm:grid-cols-4">
                                {def.filters.map((f) => (f === 'open_only'
                                    ? <label key={f} className="flex items-end gap-2 pb-2 text-sm text-gray-700"><input type="checkbox" checked={!!filters[f]} onChange={(e) => setFilters({ ...filters, [f]: e.target.checked })} /> {t('dashboard.reports.builder.filters.open_only')}</label>
                                    : <Input key={f} label={t(`dashboard.reports.builder.filters.${f}`)} type={f.startsWith('due_') ? 'date' : 'text'} value={filters[f] || ''} onChange={(e) => setFilters({ ...filters, [f]: e.target.value })} />))}
                            </div>
                            <div className="flex flex-wrap items-end gap-3">
                                <Button variant="secondary" onClick={run} disabled={!columns.length}>{t('dashboard.reports.builder.preview')}</Button>
                                <div className="w-56"><Input label={t('dashboard.reports.builder.name')} value={name} onChange={(e) => setName(e.target.value)} error={errors.name} /></div>
                                <div>
                                    <label htmlFor="rb-sched" className="mb-1.5 block text-sm font-medium text-gray-700">{t('dashboard.reports.builder.schedule')}</label>
                                    <select id="rb-sched" className={fieldClass} value={schedule} onChange={(e) => setSchedule(e.target.value)}>
                                        {['none', 'daily', 'weekly'].map((s) => <option key={s} value={s}>{t(`dashboard.reports.builder.schedules.${s}`)}</option>)}
                                    </select>
                                </div>
                                <Button onClick={save} disabled={!name.trim() || !columns.length}>{t('dashboard.reports.builder.save')}</Button>
                            </div>
                        </>
                    )}
                    {preview && (
                        <div className="overflow-x-auto">
                            <p className="mb-2 text-xs text-gray-500">{t('dashboard.reports.builder.previewNote', { count: preview.rows.length })}{preview.truncated ? ` ${t('dashboard.reports.builder.moreRows')}` : ''}</p>
                            <Table>
                                <thead><tr>{Object.values(preview.columns).map((h) => <Th key={h}>{h}</Th>)}</tr></thead>
                                <tbody>{preview.rows.map((r, i) => <tr key={i}>{Object.keys(preview.columns).map((k) => <Td key={k}>{String(r[k] ?? '')}</Td>)}</tr>)}</tbody>
                            </Table>
                        </div>
                    )}
                </div>
            </Card>

            <Card title={t('dashboard.reports.builder.mine')}>
                {data.reports.length === 0 ? <p className="py-4 text-center text-sm text-gray-500">{t('dashboard.reports.builder.none')}</p> : (
                    <ul className="divide-y divide-gray-100">
                        {data.reports.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span><span className="font-medium text-gray-900">{r.name}</span> <span className="text-gray-500">· {data.catalog[r.dataset]?.label} · {t(`dashboard.reports.builder.schedules.${r.schedule}`)}</span></span>
                                <span className="flex gap-2">
                                    <Button size="sm" variant="secondary" onClick={() => download(r)}>{t('dashboard.reports.builder.download')}</Button>
                                    <Button size="sm" variant="danger" onClick={async () => { await api.delete(`/report-builder/${r.id}`); load(); }}>{t('dashboard.reports.builder.delete')}</Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
