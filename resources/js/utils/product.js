export const TMS_HOME = '/dashboard';
export const HRMS_HOME = '/hrms';

export const PRODUCT_LABELS = { tms: 'Task Management', hrms: 'HR Management' };

/**
 * Which product shell a path belongs to. Everything under `/hrms` plus the
 * self-service My HR page renders the HRMS sidebar; every other tenant page
 * renders the TMS sidebar. Shared pages (settings, users, …) ride the TMS
 * shell — their own items are capability-gated, so an HRMS-only tenant sees
 * just Administration/Billing there.
 *
 * @param {string} pathname
 * @returns {'tms' | 'hrms'}
 */
export function productForPath(pathname = '') {
    if (pathname === '/my' || pathname.startsWith('/hrms')) return 'hrms';
    return 'tms';
}

/** Landing page of a product shell. */
export function homeForProduct(product) {
    return product === 'hrms' ? HRMS_HOME : TMS_HOME;
}
