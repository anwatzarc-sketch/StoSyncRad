import type { ErrorCode } from '@/types/generated';
import { requestStepUp } from './stepUp';

/** A failed API call with the server's structured error (Chapter 11 §11.3). */
export interface FieldError {
    property: string;
    message: string;
    code?: string;
    [key: string]: unknown;
}

export class ApiError extends Error {
    readonly status: number;
    readonly code: ErrorCode | 'NETWORK_ERROR' | string;
    readonly errors: FieldError[];
    readonly details: Record<string, unknown>;
    readonly hint: string | null;
    readonly correlationId: string | null;

    constructor(status: number, body: Record<string, any>) {
        super(typeof body.message === 'string' ? body.message : `Request failed (${status}).`);
        this.status = status;
        this.code = typeof body.code === 'string' ? body.code : status === 0 ? 'NETWORK_ERROR' : 'INTERNAL_ERROR';
        this.errors = Array.isArray(body.errors) ? body.errors : [];
        this.details = body.details && typeof body.details === 'object' ? body.details : {};
        this.hint = body.recovery?.hint ?? null;
        this.correlationId = body.correlation_id ?? null;
    }

    /** Field errors keyed by property path (e.g. `lines.0.quantity`). */
    fieldErrors(): Record<string, string> {
        const out: Record<string, string> = {};
        for (const e of this.errors) {
            out[e.property] ??= e.message;
        }

        return out;
    }
}

export interface ApiOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
    form?: FormData;
    query?: Record<string, unknown>;
    idempotencyKey?: string;
    signal?: AbortSignal;
    /** Do not prompt for step-up (used for the retry after a step-up). */
    noStepUp?: boolean;
}

function readCookie(name: string): string | null {
    const match = document.cookie.split('; ').find((c) => c.startsWith(name + '='));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

let csrfPromise: Promise<unknown> | null = null;

async function ensureCsrfCookie(force = false): Promise<void> {
    if (!force && readCookie('XSRF-TOKEN')) {
        return;
    }
    csrfPromise ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).finally(() => {
        csrfPromise = null;
    });
    await csrfPromise;
}

export function buildUrl(path: string, query?: Record<string, unknown>): string {
    const url = new URL(path, window.location.origin);
    const add = (key: string, value: unknown) => {
        if (value === undefined || value === null || value === '') {
            return;
        }
        if (Array.isArray(value)) {
            value.forEach((v) => add(`${key}[]`, v));
        } else if (typeof value === 'object') {
            Object.entries(value as Record<string, unknown>).forEach(([k, v]) => add(`${key}[${k}]`, v));
        } else {
            url.searchParams.append(key, String(value));
        }
    };
    Object.entries(query ?? {}).forEach(([k, v]) => add(k, v));

    return url.pathname + url.search;
}

async function send(path: string, opts: ApiOptions, retriedCsrf = false): Promise<Response> {
    const method = opts.method ?? 'GET';
    if (method !== 'GET') {
        await ensureCsrfCookie();
    }
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }
    if (opts.idempotencyKey) {
        headers['Idempotency-Key'] = opts.idempotencyKey;
    }
    let body: BodyInit | undefined;
    if (opts.form) {
        body = opts.form;
    } else if (opts.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(opts.body);
    }
    let res: Response;
    try {
        res = await fetch(buildUrl(path, opts.query), { method, headers, body, credentials: 'same-origin', signal: opts.signal });
    } catch (e) {
        if ((e as Error).name === 'AbortError') {
            throw e;
        }
        throw new ApiError(0, { message: 'No connection. Your request may not have reached the server — check the document before retrying.' });
    }
    if (res.status === 419 && !retriedCsrf) {
        await ensureCsrfCookie(true);

        return send(path, opts, true);
    }

    return res;
}

/**
 * JSON API call (session cookie + XSRF, Chapter 12 §12.11). Handles the cross-cutting outcomes:
 * signed out → login; step-up → prompt and retry once with the same Idempotency-Key; pending
 * password change / MFA enrolment → account page. Everything else is thrown as ApiError.
 */
export async function api<T = any>(path: string, opts: ApiOptions = {}): Promise<T> {
    const res = await send(path, opts);
    if (res.ok) {
        if (res.status === 204) {
            return undefined as T;
        }
        const type = res.headers.get('content-type') ?? '';

        return (type.includes('json') ? res.json() : res.text()) as Promise<T>;
    }
    const payload = await res.json().catch(() => ({}));
    const error = new ApiError(res.status, payload);
    if (error.code === 'UNAUTHENTICATED' && !window.location.pathname.startsWith('/login') && !window.location.pathname.startsWith('/setup')) {
        window.location.assign('/login');
    }
    if (error.code === 'STEP_UP_REQUIRED' && !opts.noStepUp && (await requestStepUp())) {
        return api<T>(path, { ...opts, noStepUp: true });
    }
    if ((error.code === 'PASSWORD_CHANGE_REQUIRED' || error.code === 'MFA_ENROLLMENT_REQUIRED') && !window.location.pathname.startsWith('/account')) {
        window.location.assign(`/account?required=${error.code}`);
    }
    throw error;
}

export const get = <T = any>(path: string, query?: Record<string, unknown>, signal?: AbortSignal) => api<T>(path, { query, signal });
export const post = <T = any>(path: string, body?: unknown, opts: Omit<ApiOptions, 'method' | 'body'> = {}) => api<T>(path, { ...opts, method: 'POST', body });
export const put = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'PUT', body });
export const patch = <T = any>(path: string, body?: unknown) => api<T>(path, { method: 'PATCH', body });
export const del = <T = any>(path: string) => api<T>(path, { method: 'DELETE' });

/** Download a protected file (PDF, CSV…) through the session and hand it to the browser. */
export async function download(path: string, fallbackName = 'download', retried = false): Promise<void> {
    const res = await send(path, {});
    if (!res.ok) {
        const error = new ApiError(res.status, await res.json().catch(() => ({})));
        if (error.code === 'STEP_UP_REQUIRED' && !retried && (await requestStepUp())) {
            return download(path, fallbackName, true);
        }
        throw error;
    }
    const disposition = res.headers.get('content-disposition') ?? '';
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName;
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/** Human message for an error, with the correlation id people quote to support. */
export function describeError(e: unknown): string {
    if (e instanceof ApiError) {
        return e.correlationId && e.status >= 500 ? `${e.message} (ref ${e.correlationId})` : e.message;
    }

    return e instanceof Error ? e.message : 'Something went wrong.';
}
