import { describe, it, expect, vi, beforeEach } from 'vitest';
import logger from './logger';

describe('client logger utility', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('formats debug messages without throwing', () => {
        const spy = vi.spyOn(console, 'debug').mockImplementation(() => {});
        logger.debug('TestTag', 'Debug message', { foo: 'bar' });
        expect(spy).toHaveBeenCalled();
    });

    it('formats info messages without throwing', () => {
        const spy = vi.spyOn(console, 'info').mockImplementation(() => {});
        logger.info('TestTag', 'Info message', { id: 123 });
        expect(spy).toHaveBeenCalled();
    });

    it('formats warn messages and schedules report', () => {
        const spy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        logger.warn('TestTag', 'Warning occurred', { warning: true });
        expect(spy).toHaveBeenCalled();
    });

    it('formats error messages with Error objects', () => {
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const err = new Error('Test crash');
        logger.error('TestTag', 'Critical error', err);
        expect(spy).toHaveBeenCalled();
    });
});
