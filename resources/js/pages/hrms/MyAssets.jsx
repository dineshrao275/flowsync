import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import EmptyState from '../../components/ui/EmptyState';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import ContentSkeleton from '../../components/ui/ContentSkeleton';
import { useToast } from '../../context/ToastContext';
import { useSetCrumbs } from '../../context/BreadcrumbContext';
import usePageTitle from '../../hooks/usePageTitle';
import { assetConditionLabel } from '../../utils/hrmsAssets';

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
    const { t } = useTranslation();
    usePageTitle(t('hrms.myAssets.pageTitle'));
    const setCrumbs = useSetCrumbs();
    const navigate = useNavigate();
    const toast = useToast();

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    const [damaging, setDamaging] = useState(null);
    const [damageNote, setDamageNote] = useState('');
    const [damageErrors, setDamageErrors] = useState({});

    useEffect(() => {
        setCrumbs([{ label: 'HRMS', to: '/hrms' }, { label: t('hrms.myAssets.pageTitle') }]);
    }, [setCrumbs, t]);

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

                setError(t('hrms.myAssets.loadFailed'));
            });
    }, [navigate, t]);

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function acknowledge(assignment) {
        try {
            await api.post(`/hrms/assets/assignments/${assignment.id}/acknowledge`, {});
            toast.success(t('hrms.myAssets.recorded'));
            load();
        } catch {
            setError(t('hrms.myAssets.acknowledgeFailed'));
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
            toast.success(t('hrms.myAssets.damageReported'));
            setDamaging(null);
            setDamageNote('');
            load();
        } catch (err) {
            setDamageErrors(err.response?.data?.errors ?? { form: [t('hrms.myAssets.damageFailed')] });
        }
    }

    const rows = data?.assignments ?? null;

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-gray-900">{t('hrms.myAssets.pageTitle')}</h2>
                <p className="mt-0.5 text-sm text-gray-500">
                    {rows ? (rows.length === 1 ? t('hrms.myAssets.handoverCountOne') : t('hrms.myAssets.handoverCount', { count: rows.length })) : '—'}
                </p>
            </div>

            {error && <Alert>{error}</Alert>}

            {!rows ? (
                <ContentSkeleton size="md" />
            ) : rows.length === 0 ? (
                <EmptyState title={t('hrms.myAssets.emptyTitle')} hint={t('hrms.myAssets.emptyHint')} />
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
                                            {t('hrms.myAssets.out', { condition: assetConditionLabel(t, assignment.condition_out) })}
                                            {assignment.acknowledged_at ? '' : ` · ${t('hrms.myAssets.awaitingSignature')}`}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        {!assignment.acknowledged_at && (
                                            <Button size="sm" onClick={() => acknowledge(assignment)}>{t('hrms.myAssets.acknowledge')}</Button>
                                        )}
                                        <Button size="sm" variant="secondary" onClick={() => { setDamaging(assignment); setDamageNote(''); setDamageErrors({}); }}>
                                            {t('hrms.myAssets.reportDamage')}
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Modal open={!!damaging} onClose={() => setDamaging(null)} title={t('hrms.myAssets.damageTitle', { code: damaging?.asset?.asset_code ?? '' })}>
                <form onSubmit={reportDamage} className="grid gap-3 px-6 py-5">
                    <Select label={t('hrms.myAssets.conditionIn')} value="damaged" disabled>
                        <option value="damaged">{assetConditionLabel(t, 'damaged')}</option>
                    </Select>
                    <Input label={t('hrms.myAssets.whatHappened')} value={damageNote} error={damageErrors.return_note} onChange={(e) => setDamageNote(e.target.value)} />
                    <div><Button type="submit" variant="danger">{t('hrms.myAssets.sendToTriage')}</Button></div>
                </form>
            </Modal>
        </div>
    );
}
