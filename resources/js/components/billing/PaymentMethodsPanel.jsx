import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import api, { fieldErrors } from '../../services/api';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import ContentSkeleton from '../ui/ContentSkeleton';
import { useConfirm } from '../ui/ConfirmDialog';
import { useToast } from '../../context/ToastContext';

/**
 * Saved cards of the organisation. Cards are added on the provider's hosted page (card numbers never reach this app);
 * the list, default and removal go through our API, which checks every card belongs to this tenant.
 */
export default function PaymentMethodsPanel({ canManage, reloadKey = 0 }) {
    const { t } = useTranslation();
    const toast = useToast();
    const confirm = useConfirm();
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const { data } = await api.get('/billing/payment-methods');
            setState(data);
        } catch {
            setState({ methods: [], supported: false });
        }
    }, []);

    useEffect(() => { load(); }, [load, reloadKey]);

    async function act(fn, success) {
        setBusy(true);
        try {
            const res = await fn();
            if (success) toast.success(res?.data?.message || success);
            await load();
        } catch (e) {
            toast.error(fieldErrors(e).form || e.response?.data?.message || t('admin.subscription.somethingWrong'));
        } finally {
            setBusy(false);
        }
    }

    async function addCard() {
        setBusy(true);
        try {
            const { data } = await api.post('/billing/payment-methods/setup');
            window.location.href = data.url;
        } catch (e) {
            toast.error(e.response?.data?.message || t('admin.subscription.somethingWrong'));
            setBusy(false);
        }
    }

    async function remove(card) {
        if (!(await confirm({ title: t('billing.sub.removeCardTitle'), message: t('billing.sub.removeCardMessage', { last4: card.last4 }), variant: 'danger' }))) return;
        await act(() => api.delete(`/billing/payment-methods/${card.id}`), t('billing.sub.cardRemoved'));
    }

    if (!state) return <ContentSkeleton size="sm" />;
    if (!state.supported) return null;

    return (
        <section>
            <div className="mb-2 flex items-center justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">{t('billing.sub.paymentMethods')}</h3>
                    <p className="text-xs text-gray-500">{t('billing.sub.paymentMethodsSubtitle')}</p>
                </div>
                {canManage && <Button size="sm" variant="secondary" onClick={addCard} loading={busy}>{t('billing.sub.addCard')}</Button>}
            </div>

            {state.methods.length === 0 ? (
                <div className="rounded-lg border border-dashed border-gray-200 bg-gray-50/60 px-4 py-6 text-center text-xs text-gray-400">{t('billing.sub.noCards')}</div>
            ) : (
                <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                    {state.methods.map((card) => (
                        <li key={card.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div className="flex items-center gap-3">
                                <span className="rounded bg-gray-100 px-2 py-1 text-xs font-semibold uppercase text-gray-600">{card.brand}</span>
                                <span className="font-mono text-sm text-gray-900">•••• {card.last4}</span>
                                <span className="text-xs text-gray-500">{t('billing.sub.cardExpires', { month: String(card.exp_month).padStart(2, '0'), year: card.exp_year })}</span>
                                {card.is_default && <Badge tone="success">{t('billing.sub.defaultCard')}</Badge>}
                            </div>
                            {canManage && (
                                <div className="flex gap-2">
                                    {!card.is_default && (
                                        <Button size="sm" variant="secondary" disabled={busy} onClick={() => act(() => api.post(`/billing/payment-methods/${card.id}/default`), t('billing.sub.defaultUpdated'))}>
                                            {t('billing.sub.makeDefault')}
                                        </Button>
                                    )}
                                    <Button size="sm" variant="danger" disabled={busy} onClick={() => remove(card)}>{t('billing.sub.removeCard')}</Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
