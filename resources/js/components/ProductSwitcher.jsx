import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../context/AuthContext';
import { useActiveProduct } from '../context/ProductContext';

/**
 * The TMS ↔ HRMS switch in the topbar.
 *
 * Shown only for the *other* product the caller can actually reach: the
 * tenant must subscribe to it (`product:tms` / `product:hrms`) and the user
 * must hold a way in (a TMS landing permission for TMS, the `hrms.core`
 * module for HRMS). A platform super admin never sees it — products are a
 * tenant concept, and the platform sidebar is already product-free. The
 * target shell's own route gates still apply, so this navigates, never
 * authorizes.
 */
export default function ProductSwitcher() {
    const { user, check, can, hasModule } = useAuth();
    const { activeProduct, switchProduct } = useActiveProduct();
    const { t } = useTranslation();
    const navigate = useNavigate();

    if (!user || (user.is_super_admin && !user.impersonating)) return null;

    const product = activeProduct;
    const canReachTms = check('product:tms') && (can('dashboard.view') || can('workspaces.view'));
    const canReachHrms = check('product:hrms') && hasModule('hrms.core');

    if (product === 'tms' && !canReachHrms) return null;
    if (product === 'hrms' && !canReachTms) return null;

    const toHrms = product === 'tms';
    const target = toHrms ? '/hrms' : can('dashboard.view') ? '/dashboard' : '/workspaces';
    const label = toHrms ? 'HR' : 'TMS';
    const title = toHrms ? t('topbar.productSwitcher.toHrms') : t('topbar.productSwitcher.toTms');

    return (
        <button
            type="button"
            onClick={() => {
                switchProduct(toHrms ? 'hrms' : 'tms');
                navigate(target);
            }}
            title={title}
            aria-label={title}
            className="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 active:scale-95"
        >
            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4" />
            </svg>
            {label}
        </button>
    );
}
