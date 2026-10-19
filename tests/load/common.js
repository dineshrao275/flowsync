// Shared helpers for the k6 profiles (F11.6). Run against a seeded stack:
//   k6 run -e BASE=http://localhost -e EMAIL=owner@tenant-001.test -e PASSWORD=password tests/load/browse.js
import http from 'k6/http';
import { check } from 'k6';

export const BASE = __ENV.BASE || 'http://localhost';
export const EMAIL = __ENV.EMAIL || 'owner@tenant-001.test';
export const PASSWORD = __ENV.PASSWORD || 'password';

/** Sign in once per virtual user; k6 keeps the session cookie jar per VU. Returns the XSRF header to send. */
export function login() {
    http.get(`${BASE}/api/health`); // primes the XSRF cookie
    const jar = http.cookieJar();
    const xsrf = () => decodeURIComponent((jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [''])[0]);
    const res = http.post(`${BASE}/api/auth/login`, JSON.stringify({ email: EMAIL, password: PASSWORD }), {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    });
    check(res, { 'signed in': (r) => r.status === 200 });

    return { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() };
}

export function get(path, headers, name) {
    const res = http.get(`${BASE}/api${path}`, { headers, tags: { name: name || path } });
    check(res, { [`${name || path} ok`]: (r) => r.status === 200 });

    return res;
}
