import { useEffect, useState } from 'react';
import ConfirmablePasswordController from '@/actions/Laravel/Fortify/Http/Controllers/ConfirmablePasswordController';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson(
    url: string,
    body: Record<string, unknown> = {},
): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });
}

/**
 * Reveal a service password through the audited, step-up protected endpoint. When the server
 * answers 423 (no recent password confirmation), ask for the user's password, confirm it with
 * Fortify and retry once; a login without a password is told to set one instead. The password
 * lives only in component state, never in Inertia props or history, and with `hideAfterSeconds`
 * it is cleared again after that many seconds.
 */
export function useCredentialsReveal(
    url: string,
    { hideAfterSeconds }: { hideAfterSeconds?: number } = {},
) {
    const [password, setPassword] = useState<string | null>(null);
    const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
    const [needsConfirmation, setNeedsConfirmation] = useState(false);
    const [noPassword, setNoPassword] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (password === null || hideAfterSeconds === undefined) {
            return;
        }

        const hideAt = Date.now() + hideAfterSeconds * 1000;
        setSecondsLeft(hideAfterSeconds);
        const timer = window.setInterval(() => {
            const left = Math.ceil((hideAt - Date.now()) / 1000);

            if (left <= 0) {
                setPassword(null);
                setSecondsLeft(null);

                return;
            }

            setSecondsLeft(left);
        }, 1000);

        return () => window.clearInterval(timer);
    }, [password, hideAfterSeconds]);

    const hide = () => {
        setPassword(null);
        setSecondsLeft(null);
    };

    const reveal = async () => {
        setProcessing(true);
        setError(null);

        try {
            const response = await postJson(url);

            if (response.status === 423) {
                const body = (await response.json().catch(() => ({}))) as {
                    code?: string;
                };
                setNoPassword(body.code === 'password_not_set');
                setNeedsConfirmation(true);

                return;
            }

            if (response.status === 429) {
                const wait = Number(response.headers.get('Retry-After'));
                setError(
                    wait > 0
                        ? `The password was shown too many times. Try again in ${Math.ceil(wait / 60)} min.`
                        : 'The password was shown too many times. Try again later.',
                );

                return;
            }

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as {
                    message?: string;
                };
                setError(
                    response.status === 422 && body.message
                        ? body.message
                        : 'Could not reveal the password.',
                );

                return;
            }

            const data = (await response.json()) as { password: string | null };
            setPassword(data.password);
        } catch {
            setError(
                'Could not reveal the password. Check your connection and try again.',
            );
        } finally {
            setProcessing(false);
        }
    };

    const confirm = async (accountPassword: string) => {
        setProcessing(true);
        setError(null);

        try {
            const response = await postJson(
                ConfirmablePasswordController.store.url(),
                { password: accountPassword },
            );

            if (!response.ok) {
                setError(
                    response.status === 422
                        ? 'The password is incorrect.'
                        : 'Could not confirm your password. Try again.',
                );

                return;
            }

            setNeedsConfirmation(false);
        } finally {
            setProcessing(false);
        }

        await reveal();
    };

    return {
        password,
        secondsLeft,
        hide,
        reveal,
        confirm,
        needsConfirmation,
        noPassword,
        cancelConfirmation: () => setNeedsConfirmation(false),
        processing,
        error,
    };
}
