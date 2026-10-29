import { describe, expect, it } from 'vitest';
import {
    HRMS_HOME,
    TMS_HOME,
    homeForProduct,
    productAvailable,
    productForPath,
    productStorageKey,
    resolveProduct,
} from './product';

const both = { id: 7, tenant: { id: 3 }, products: { tms: true, hrms: true } };
const hrmsOnly = { ...both, products: { tms: false, hrms: true } };
const tmsOnly = { ...both, products: { tms: true, hrms: false } };

describe('productForPath', () => {
    it('resolves the HRMS hub and its detail pages to hrms', () => {
        expect(productForPath('/hrms')).toBe('hrms');
        expect(productForPath('/hrms/employees')).toBe('hrms');
        expect(productForPath('/hrms/employees/7')).toBe('hrms');
    });

    it('resolves the self-service My HR page to hrms', () => {
        expect(productForPath('/my')).toBe('hrms');
    });

    it('resolves TMS-owned pages to tms', () => {
        expect(productForPath('/dashboard')).toBe('tms');
        expect(productForPath('/workspaces')).toBe('tms');
        expect(productForPath('/workspaces/12')).toBe('tms');
        expect(productForPath('/projects/9')).toBe('tms');
        expect(productForPath('/reports/builder')).toBe('tms');
        expect(productForPath('/webhooks')).toBe('tms');
    });

    it('returns null for shared tenant and platform pages', () => {
        expect(productForPath('/settings')).toBeNull();
        expect(productForPath('/users')).toBeNull();
        expect(productForPath('/subscription')).toBeNull();
        expect(productForPath('/support')).toBeNull();
        expect(productForPath('/')).toBeNull();
        expect(productForPath('')).toBeNull();
    });
});

describe('homeForProduct', () => {
    it('lands each shell on its own home', () => {
        expect(homeForProduct('hrms')).toBe(HRMS_HOME);
        expect(homeForProduct('tms')).toBe(TMS_HOME);
    });
});

describe('productAvailable', () => {
    it('mirrors the session product map', () => {
        expect(productAvailable(both, 'tms')).toBe(true);
        expect(productAvailable(hrmsOnly, 'tms')).toBe(false);
        expect(productAvailable(hrmsOnly, 'hrms')).toBe(true);
        expect(productAvailable(tmsOnly, 'hrms')).toBe(false);
    });

    it('lets a platform super admin use both products', () => {
        expect(productAvailable({ is_super_admin: true, impersonating: false }, 'tms')).toBe(true);
        expect(productAvailable({ is_super_admin: true, impersonating: false }, 'hrms')).toBe(true);
    });

    it('treats a missing product map as available', () => {
        expect(productAvailable({ id: 1 }, 'tms')).toBe(true);
        expect(productAvailable({ id: 1 }, 'hrms')).toBe(true);
    });
});

describe('resolveProduct', () => {
    it('uses the owned URL when the caller is entitled', () => {
        expect(resolveProduct({ pathname: '/dashboard', storedProduct: 'hrms', user: both })).toBe('tms');
        expect(resolveProduct({ pathname: '/hrms', storedProduct: 'tms', user: both })).toBe('hrms');
    });

    it('keeps the active product on a shared route', () => {
        expect(resolveProduct({ pathname: '/subscription', storedProduct: 'hrms', user: both })).toBe('hrms');
        expect(resolveProduct({ pathname: '/users', storedProduct: 'tms', user: both })).toBe('tms');
    });

    it('prefers navigation state over the stored product on shared routes', () => {
        expect(
            resolveProduct({ pathname: '/users', stateProduct: 'hrms', storedProduct: 'tms', user: both }),
        ).toBe('hrms');
    });

    it('does not use a stored/state product the tenant no longer has', () => {
        expect(resolveProduct({ pathname: '/settings', storedProduct: 'tms', user: hrmsOnly })).toBe('hrms');
        expect(resolveProduct({ pathname: '/settings', stateProduct: 'tms', user: hrmsOnly })).toBe('hrms');
    });

    it('does not persist an owned URL the caller cannot use', () => {
        expect(resolveProduct({ pathname: '/dashboard', storedProduct: 'hrms', user: hrmsOnly })).toBe('hrms');
    });

    it('falls back to the only entitled product', () => {
        expect(resolveProduct({ pathname: '/settings', user: hrmsOnly })).toBe('hrms');
        expect(resolveProduct({ pathname: '/settings', user: tmsOnly })).toBe('tms');
    });

    it('defaults a combined tenant to tms on a shared route with no history', () => {
        expect(resolveProduct({ pathname: '/settings', user: both })).toBe('tms');
    });
});

describe('productStorageKey', () => {
    it('scopes the preference by tenant and account', () => {
        expect(productStorageKey(both)).toBe('flowsync.activeProduct.3.7');
        expect(productStorageKey({ ...both, tenant: { id: 4 } })).toBe('flowsync.activeProduct.4.7');
        expect(productStorageKey(null)).toBe('flowsync.activeProduct.platform.anonymous');
    });
});
