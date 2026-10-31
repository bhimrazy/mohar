// Shared helpers for the load scenarios (loadtest/README.md). k6 2.x JavaScript API only: k6/http, k6, k6/metrics.
//
// A browser first loads a full HTML page, then navigates with Inertia XHR requests that return the page object as
// JSON (X-Inertia + X-Inertia-Version). The scenarios do the same: one HTML page per session, JSON after that.
import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';

export const BASE = __ENV.BASE_URL || 'https://mohar.test';
export const PASSWORD = __ENV.LOADTEST_PASSWORD || '';
export const EMAIL_DOMAIN = 'loadtest.example.com';

/** Requests answered 429 by a rate limiter (all virtual users share one source IP). Not counted as errors. */
export const rateLimited = new Rate('rate_limited');
/** Pages whose body did not have what the scenario needed (an Inertia page object, an id to follow). */
export const contentErrors = new Counter('content_errors');
/** Answers outside 2xx and 3xx by status class, per page, so a summary says which status the errors were (#567). */
export const STATUS_CLASSES = ['429', '4xx', '5xx', 'none'];
const statusCounters = Object.fromEntries(
    STATUS_CLASSES.map((c) => [c, new Counter(`status_${c}`)]),
);

function countStatus(status, name) {
    if (status >= 200 && status < 400) {
        return;
    }
    const statusClass =
        status === 429
            ? '429'
            : status >= 500
              ? '5xx'
              : status >= 400
                ? '4xx'
                : 'none';
    statusCounters[statusClass].add(1, { page: name });
}

const PAGE_PATTERN = /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/;

/** 2xx and 3xx are successes; 429 is expected for the IP-limited storefront endpoints. */
export const allow429 = http.expectedStatuses({ min: 200, max: 399 }, 429);

// THINK_MIN and THINK_MAX (seconds) replace every pause with one range, to model people who read each page
// (e.g. 10 and 30). Unset, each call keeps its own range (1–3 s by default).
const THINK_MIN = parseFloat(__ENV.THINK_MIN || '');
const THINK_MAX = parseFloat(__ENV.THINK_MAX || '');
const THINK_OVERRIDE = Number.isFinite(THINK_MIN) && Number.isFinite(THINK_MAX);

export function think(min = 1, max = 3) {
    if (THINK_OVERRIDE) {
        [min, max] = [THINK_MIN, THINK_MAX];
    }
    sleep(min + Math.random() * (max - min));
}

/**
 * A browser session: its own cookie jar (so one VU can be an anonymous visitor and a signed-in customer at once),
 * the Inertia asset version and the signed-in user.
 */
export function newSession() {
    return { jar: new http.CookieJar(), version: null, user: null };
}

export function xsrf(session) {
    const cookies = session.jar.cookiesForURL(BASE);
    const token = cookies['XSRF-TOKEN'];

    return token && token.length > 0 ? decodeURIComponent(token[0]) : '';
}

function inertiaPage(res) {
    const contentType = String(res.headers['Content-Type'] || '');
    if (contentType.indexOf('application/json') !== -1) {
        try {
            return res.json();
        } catch (e) {
            return null;
        }
    }
    const match =
        typeof res.body === 'string' ? res.body.match(PAGE_PATTERN) : null;
    if (!match) {
        return null;
    }
    try {
        return JSON.parse(match[1]);
    } catch (e) {
        return null;
    }
}

function record(res, page, name) {
    countStatus(res.status, name);
    if (res.status === 429) {
        rateLimited.add(1, { page: name });
        return;
    }
    rateLimited.add(0, { page: name });
    check(
        res,
        { [`${name} 2xx`]: (r) => r.status >= 200 && r.status < 300 },
        { page: name },
    );
    if (res.status >= 200 && res.status < 300 && page === null) {
        contentErrors.add(1, { page: name });
    }
}

/** A full HTML page load (first visit, or a link opened in a new tab). Returns the Inertia page object or null. */
export function html(session, path, name, { limited = false } = {}) {
    const res = http.get(`${BASE}${path}`, {
        headers: { Accept: 'text/html,application/xhtml+xml' },
        jar: session.jar,
        tags: { page: name },
        ...(limited ? { responseCallback: allow429 } : {}),
    });
    const page = inertiaPage(res);
    if (page && page.version !== undefined) {
        session.version = page.version;
    }
    record(res, page, name);

    return page;
}

/** A file download (invoice PDF): only the status and content type are checked. */
export function download(session, path, name, contentType) {
    const res = http.get(`${BASE}${path}`, {
        jar: session.jar,
        tags: { page: name },
    });
    record(
        res,
        String(res.headers['Content-Type'] || '').indexOf(contentType) === 0
            ? {}
            : null,
        name,
    );

    return res.status;
}

/**
 * An Inertia navigation: JSON page object. A 409 means a new asset version: reload once as HTML, like the client.
 * `limited` marks a page behind a per-IP limiter: its 429s are counted apart, not as errors.
 */
export function visit(
    session,
    path,
    name,
    extraHeaders = {},
    { limited = false } = {},
) {
    if (session.version === null) {
        return html(session, path, name, { limited });
    }
    const res = http.get(`${BASE}${path}`, {
        headers: {
            Accept: 'text/html, application/xhtml+xml',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Inertia': 'true',
            'X-Inertia-Version': String(session.version),
            ...extraHeaders,
        },
        jar: session.jar,
        tags: { page: name },
        ...(limited ? { responseCallback: allow429 } : {}),
    });
    if (res.status === 409) {
        return html(session, path, name, { limited });
    }
    const page = inertiaPage(res);
    record(res, page, name);

    return page;
}

/** A JSON endpoint (search, price preview). Returns the parsed body or null. */
export function json(
    session,
    path,
    name,
    { method = 'GET', body = null, limited = false } = {},
) {
    const params = {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(session),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        jar: session.jar,
        tags: { page: name },
        ...(limited ? { responseCallback: allow429 } : {}),
    };
    const res =
        method === 'GET'
            ? http.get(`${BASE}${path}`, params)
            : http.request(
                  method,
                  `${BASE}${path}`,
                  body ? JSON.stringify(body) : null,
                  params,
              );
    let parsed = null;
    try {
        parsed = res.status >= 200 && res.status < 300 ? res.json() : null;
    } catch (e) {
        parsed = null;
    }
    record(res, parsed, name);

    return parsed;
}

/**
 * A form submission the way Inertia sends it (XHR with the XSRF header). Redirects are not followed, so the
 * response time is the action alone; returns { status, location }.
 */
export function submit(
    session,
    method,
    path,
    fields,
    name,
    { limited = false } = {},
) {
    const res = http.request(method, `${BASE}${path}`, fields, {
        headers: {
            Accept: 'text/html, application/xhtml+xml',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Inertia': 'true',
            ...(session.version !== null
                ? { 'X-Inertia-Version': String(session.version) }
                : {}),
            'X-XSRF-TOKEN': xsrf(session),
            Origin: BASE,
        },
        jar: session.jar,
        redirects: 0,
        tags: { page: name },
        ...(limited ? { responseCallback: allow429 } : {}),
    });
    countStatus(res.status, name);
    if (res.status === 429) {
        rateLimited.add(1, { page: name });
    } else {
        rateLimited.add(0, { page: name });
        check(
            res,
            { [`${name} redirects`]: (r) => r.status >= 300 && r.status < 400 },
            { page: name },
        );
    }

    return {
        status: res.status,
        location: String(res.headers['Location'] || ''),
    };
}

/** Sign in once per VU with the seeded account; staff land on /admin, clients on /client. */
export function signIn(session, email) {
    if (session.user === email) {
        return true;
    }
    if (PASSWORD === '') {
        fail('LOADTEST_PASSWORD is not set');
    }
    html(session, '/login', 'login_form');
    const result = submit(
        session,
        'POST',
        '/login',
        { email, password: PASSWORD },
        'login',
    );
    const ok =
        result.status >= 300 &&
        result.status < 400 &&
        result.location.indexOf('/login') === -1;
    if (ok) {
        session.user = email;
    } else {
        contentErrors.add(1, { page: 'login' });
    }

    return ok;
}

/** Rows of a prop that is a plain list, a resource collection ({ data: [...] }) or a paginator. */
export function rows(value) {
    if (Array.isArray(value)) {
        return value;
    }
    if (value && Array.isArray(value.data)) {
        return value.data;
    }

    return [];
}

export function pick(list) {
    return list.length > 0
        ? list[Math.floor(Math.random() * list.length)]
        : null;
}

/** Spread virtual users over the seeded accounts: VU n of a scenario gets a different account each run level. */
export function accountIndex(vu, offset, total) {
    return ((vu * 7919 + offset) % total) + 1;
}
