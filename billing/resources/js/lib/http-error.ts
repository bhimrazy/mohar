import { http } from '@inertiajs/react';

/** What a failed `useHttp` request carried (an `HttpResponseError`, read by shape: `@inertiajs/core` is not a direct dependency). */
export type HttpFailure = {
    status: number;
    /** The JSON body, or an empty object when it was not JSON. */
    body: Record<string, unknown>;
    /** Header names are lower-cased by the client. */
    headers: Record<string, string>;
};

function parseResponse(
    status: number,
    data: unknown,
    headers: Record<string, string> | undefined,
): HttpFailure {
    let body: Record<string, unknown> = {};

    try {
        const parsed: unknown = JSON.parse(String(data));

        if (parsed && typeof parsed === 'object') {
            body = parsed as Record<string, unknown>;
        }
    } catch {
        // Not JSON (a proxy error page): the status alone decides the message.
    }

    return { status, body, headers: headers ?? {} };
}

export function httpFailure(error: unknown): HttpFailure | null {
    const response = (
        error as {
            response?: {
                status?: unknown;
                data?: unknown;
                headers?: Record<string, string>;
            };
        } | null
    )?.response;

    return typeof response?.status === 'number'
        ? parseResponse(response.status, response.data, response.headers)
        : null;
}

/**
 * A POST through Inertia's HTTP client (the one `useHttp` uses, so the XSRF header and `X-Requested-With` come from it)
 * that resolves with the status and JSON body of any answer instead of throwing, for a caller that needs the body of a
 * 422 (`useHttp` turns that into field errors and drops the rest). A network failure still rejects.
 */
export async function postForJson(
    url: string,
    data?: Record<string, unknown>,
): Promise<HttpFailure & { ok: boolean }> {
    try {
        const response = await http.getClient().request({
            method: 'post',
            url,
            data,
            headers: { Accept: 'application/json' },
        });

        return {
            ...parseResponse(response.status, response.data, response.headers),
            ok: true,
        };
    } catch (error) {
        const failure = httpFailure(error);

        if (!failure) {
            throw error;
        }

        return { ...failure, ok: false };
    }
}
