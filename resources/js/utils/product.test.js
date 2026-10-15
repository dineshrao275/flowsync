import { describe, expect, it } from 'vitest';
import { HRMS_HOME, TMS_HOME, homeForProduct, productForPath } from './product';

describe('productForPath', () => {
    it('resolves the HRMS hub and its detail pages to hrms', () => {
        expect(productForPath('/hrms')).toBe('hrms');
        expect(productForPath('/hrms/employees')).toBe('hrms');
        expect(productForPath('/hrms/employees/7')).toBe('hrms');
    });

    it('resolves the self-service My HR page to hrms', () => {
        expect(productForPath('/my')).toBe('hrms');
    });

    it('resolves everything else to tms', () => {
        expect(productForPath('/dashboard')).toBe('tms');
        expect(productForPath('/workspaces')).toBe('tms');
        expect(productForPath('/settings')).toBe('tms');
        expect(productForPath('/')).toBe('tms');
        expect(productForPath('')).toBe('tms');
    });
});

describe('homeForProduct', () => {
    it('lands each shell on its own home', () => {
        expect(homeForProduct('hrms')).toBe(HRMS_HOME);
        expect(homeForProduct('tms')).toBe(TMS_HOME);
    });
});
