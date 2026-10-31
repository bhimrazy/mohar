/**
 * Fired on `window` (cancelable) when a step needs a recent password and will retry itself. `PasswordConfirmationGate`
 * cancels it, asks for the password and calls `resolve(true)` once it is confirmed, or `resolve(false)` on cancel.
 */
export const STEP_UP_CONFIRM_EVENT = 'step-up:confirm';

/**
 * Header on an Inertia form whose component retries after the dialog itself (`useStepUpForm`), so the gate does not
 * replay the visit as well. A replay would drop the form's callbacks, such as opening the next setup step.
 */
export const STEP_UP_CALLER_RETRIES_HEADER = 'X-Step-Up-Caller-Retries';

export type StepUpConfirmDetail = {
    noPassword?: boolean;
    resolve: (confirmed: boolean) => void;
};

/** How long a password confirmation lasts, for text: "15 minutes", "1 minute" (shared `auth.step_up_minutes`). */
export function stepUpWindow(minutes: number | undefined): string {
    const value = minutes && minutes > 0 ? minutes : 15;

    return value === 1 ? '1 minute' : `${value} minutes`;
}

/** Thrown by `withStepUp` when the person cancels the dialog, so a multi-step flow stops instead of asking again. */
export class StepUpCancelled extends Error {
    constructor() {
        super('Password confirmation was cancelled.');
        this.name = 'StepUpCancelled';
    }
}

/**
 * The gate's next pending request. A `requestStepUp()` caller still waiting on the one it replaces is told it was not
 * confirmed, so its `await` always settles.
 */
export function replacePending<
    T extends { resolve?: (confirmed: boolean) => void },
>(previous: T | null, next: T): T {
    if (previous !== next) {
        previous?.resolve?.(false);
    }

    return next;
}

/** Ask a mounted gate for the password; false on cancel or when no gate handled the request. */
export function requestStepUp(
    target: EventTarget,
    noPassword?: boolean,
): Promise<boolean> {
    return new Promise((resolve) => {
        const handled = !target.dispatchEvent(
            new CustomEvent<StepUpConfirmDetail>(STEP_UP_CONFIRM_EVENT, {
                cancelable: true,
                detail: { noPassword, resolve },
            }),
        );

        if (!handled) {
            resolve(false);
        }
    });
}

/**
 * A 423 step-up refusal from `useHttp` (an `HttpResponseError`, checked by shape because `@inertiajs/core` is not a
 * direct dependency), with whether the login has no password; null for any other error.
 */
export function stepUpRefusal(error: unknown): { noPassword: boolean } | null {
    const response = (
        error as { response?: { status?: unknown; data?: unknown } } | null
    )?.response;

    if (response?.status !== 423) {
        return null;
    }

    try {
        const body = JSON.parse(String(response.data)) as {
            code?: string;
        } | null;

        return { noPassword: body?.code === 'password_not_set' };
    } catch {
        return { noPassword: false };
    }
}

/**
 * Runs a `useHttp` request; when it is refused for step-up, asks for the password and runs it once more. A cancelled
 * dialog (or a page with no gate) throws `StepUpCancelled`; any other error is rethrown as it is.
 */
export async function withStepUp<T>(
    request: () => Promise<T>,
    target: EventTarget = window,
): Promise<T> {
    try {
        return await request();
    } catch (error) {
        const refusal = stepUpRefusal(error);

        if (!refusal) {
            throw error;
        }

        if (await requestStepUp(target, refusal.noPassword)) {
            return request();
        }

        throw new StepUpCancelled();
    }
}

/**
 * For a step that is not a `useHttp` request (a passkey ceremony): asks the server with a probe on a safe route behind
 * the same middleware, which never reaches the controller, and opens the dialog when it is refused. Resolves whether
 * the step may go ahead; a probe that fails to answer lets it go ahead, and the step's own error then shows.
 */
export async function ensureStepUp(
    probeUrl: string,
    target: EventTarget = window,
): Promise<boolean> {
    let response: Response;

    try {
        response = await fetch(probeUrl, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Step-Up-Check': '1',
            },
        });
    } catch {
        return true;
    }

    if (response.status !== 423) {
        return true;
    }

    const body = (await response.json().catch(() => null)) as {
        code?: string;
    } | null;

    return requestStepUp(target, body?.code === 'password_not_set');
}
