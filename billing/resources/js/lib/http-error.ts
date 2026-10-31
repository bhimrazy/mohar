import { http } from '@inertiajs/react';

/** The status, JSON body and headers of an answer. */
export type JsonResponse = {
    status: number;
    /** The JSON body, or an empty object when it was not JSON. */
    body: Record<string, unknown>;
    /** Header names are lower-cased by the client. */
    headers: Record<string, string>;
};

/** What a failed `useHttp` request carried (an `HttpResponseError`, read by shape: `@inertiajs/core` is not a direct dependency). */
export type HttpFailure = JsonResponse;

/** What `postForJson` resolves with: the answer, and whether its status was a success. */
export type JsonResult = JsonResponse & { ok: boolean };

function parseResponse(
    status: number,
    data: unknown,
    headers: Record<string, string> | undefined,
): JsonResponse {
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

/** Plain-words explanation of a failed request, with what to do next, for showing beside the control that sent it. */
export function failureMessage(error: unknown): string {
    const status = httpFailure(error)?.status;

    if (status === undefined) {
        return "We couldn't reach the server. Check your connection and try again.";
    }

    if (status === 419) {
        return 'Your session expired. Reload the page and try again.';
    }

    if (status === 429) {
        return 'Too many attempts. Wait a minute and try again.';
    }

    return 'Something went wrong on our side. Try again in a moment.';
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
): Promise<JsonResult> {
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
