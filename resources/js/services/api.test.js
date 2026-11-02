import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import logger from '../utils/logger';
import api from './api';

describe('api response interceptor logging', () => {
    const original = api.defaults.adapter;

    afterEach(() => {
        api.defaults.adapter = original;
        vi.restoreAllMocks();
    });

    // Superseded requests are aborted on purpose (useResource, filtered lists); logging them as
    // errors made every search keystroke look like a network failure in the console.
    it('does not log a deliberately cancelled request as an error', async () => {
        const error = vi.spyOn(logger, 'error').mockImplementation(() => {});
        api.defaults.adapter = () => Promise.reject(new axios.CanceledError('canceled'));

        await expect(api.get('/hrms/employees')).rejects.toSatisfy((e) => axios.isCancel(e));
        expect(error).not.toHaveBeenCalled();
    });

    it('still logs a genuine network failure', async () => {
        const error = vi.spyOn(logger, 'error').mockImplementation(() => {});
        api.defaults.adapter = () => Promise.reject(new axios.AxiosError('Network Error', 'ERR_NETWORK'));

        await expect(api.get('/hrms/employees')).rejects.toBeTruthy();
        expect(error).toHaveBeenCalledTimes(1);
    });
});
