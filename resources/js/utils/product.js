export const TMS_HOME = '/dashboard';
export const HRMS_HOME = '/hrms';

export const PRODUCT_LABELS = { tms: 'Task Management', hrms: 'HR Management' };

// Only product-owned roots decide the shell from the URL. Shared tenant pages
// (settings, users, billing, support, …) deliberately return `null`: their
// shell comes from the active product, so opening Administration from HRMS
// does not silently move the caller into TMS.
const TMS_ROOTS = ['/dashboard', '/workspaces', '/projects', '/search', '/timesheets', '/reports', '/webhooks'];
const HRMS_ROOTS = ['/hrms', '/my'];

function isWithin(pathname, root) {
    return pathname === root || pathname.startsWith(`${root}/`);
}

/**
 * The product that owns a route, or `null` for a shared/platform route.
 *
 * @param {string} pathname
 * @returns {'tms' | 'hrms' | null}
 */
export function productForPath(pathname = '') {
    const path = pathname || '';

    if (HRMS_ROOTS.some((root) => isWithin(path, root))) return 'hrms';
    if (TMS_ROOTS.some((root) => isWithin(path, root))) return 'tms';

    return null;
}

/** Landing page of a product shell. */
export function homeForProduct(product) {
    return product === 'hrms' ? HRMS_HOME : TMS_HOME;
}

/**
 * Mirrors `AuthContext` product checks: a non-impersonating super admin sees
 * every product, a tenant user is limited by `user.products`, and an unknown
 * payload (tests, older sessions) does not deny a product.
 */
export function productAvailable(user, product) {
    if (product !== 'tms' && product !== 'hrms') return false;
    if (!user) return true;
    if (user.is_super_admin && !user.impersonating) return true;

    return user.products?.[product] !== false;
}

/**
 * Resolve the shell that should be active for the current location.
 *
 * A product-owned URL wins *when the caller is entitled to it*. If it is not
 * (an HRMS-only tenant opening a TMS deep link), we fall through instead of
 * persisting a product the caller cannot use. Shared routes then prefer the
 * navigation state attached by the link, then the last product used in this
 * tenant/account, then the only entitled product.
 *
 * @param {{ pathname?: string, stateProduct?: string|null, storedProduct?: string|null, user?: object|null }} input
 * @returns {'tms' | 'hrms'}
 */
export function resolveProduct({ pathname = '', stateProduct = null, storedProduct = null, user = null } = {}) {
    const owner = productForPath(pathname);
    if (owner && productAvailable(user, owner)) return owner;

    if (stateProduct && productAvailable(user, stateProduct)) return stateProduct;
    if (storedProduct && productAvailable(user, storedProduct)) return storedProduct;

    const tms = productAvailable(user, 'tms');
    const hrms = productAvailable(user, 'hrms');

    return hrms && !tms ? 'hrms' : 'tms';
}

/**
 * Per-tenant/per-account storage key. The tenant segment keeps two tenants on
 * one browser from sharing a shell preference; the account segment does the
 * same for impersonation and shared machines.
 */
export function productStorageKey(user) {
    const prefix = (typeof window !== 'undefined' && window.__FLOWSYNC_CONFIG__?.storagePrefix) || 'flowsync';
    const tenant = user?.tenant?.id ?? 'platform';
    const account = user?.id ?? 'anonymous';

    return `${prefix}.activeProduct.${tenant}.${account}`;
}
