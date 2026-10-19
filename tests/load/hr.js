// F11.6 — an HR admin working through the directory, leave and attendance reads.
import { sleep } from 'k6';
import { login, get } from './common.js';

export const options = {
    scenarios: { steady: { executor: 'constant-vus', vus: Number(__ENV.VUS || 10), duration: __ENV.DURATION || '2m' } },
    thresholds: { http_req_failed: ['rate<0.01'], 'http_req_duration{name:employees}': ['p(95)<900'] },
};

export default function () {
    const headers = login();
    get('/hrms/employees?per_page=50', headers, 'employees');
    get('/hrms/leave/requests', headers, 'leave');
    get('/hrms/attendance/today', headers, 'attendance');
    get('/hrms/inbox', headers, 'inbox');
    sleep(1 + Math.random() * 2);
}
