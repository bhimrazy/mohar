import { useEffect, useState } from 'react';
import ConfirmablePasswordController from '@/actions/Laravel/Fortify/Http/Controllers/ConfirmablePasswordController';
import { postForJson } from '@/lib/http-error';

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
            const response = await postForJson(url);

            if (response.status === 423) {
                setNoPassword(response.body.code === 'password_not_set');
                setNeedsConfirmation(true);

                return;
            }

            if (response.status === 429) {
                const wait = Number(response.headers['retry-after']);
                setError(
                    wait > 0
                        ? `The password was shown too many times. Try again in ${Math.ceil(wait / 60)} min.`
                        : 'The password was shown too many times. Try again later.',
                );

                return;
            }

            if (!response.ok) {
                const message = response.body.message;
                setError(
                    response.status === 422 && typeof message === 'string'
                        ? message
                        : response.status === 419 || response.status === 401
                          ? 'Your session expired. Reload the page, log in again if asked, and try again.'
                          : response.status === 403 || response.status === 404
                            ? "You can't see this service's password from this account. Reload the page to check your access."
                            : 'Could not show the password. Reload the page and try again; if it keeps failing, contact support.',
                );

                return;
            }

            const revealed = response.body.password;
            setPassword(typeof revealed === 'string' ? revealed : null);
        } catch {
            setError(
                'Could not show the password. Check your connection and try again.',
            );
        } finally {
            setProcessing(false);
        }
    };

    /** Confirms the account password, then reveals. Resolves true when the password was accepted. */
    const confirm = async (accountPassword: string): Promise<boolean> => {
        setProcessing(true);
        setError(null);

        try {
            const response = await postForJson(
                ConfirmablePasswordController.store.url(),
                { password: accountPassword },
            );

            if (!response.ok) {
                setError(
                    response.status === 422
                        ? 'The password is incorrect.'
                        : response.status === 419 || response.status === 401
                          ? 'Your session expired. Reload the page, log in again if asked, and try again.'
                          : response.status === 429
                            ? 'Too many attempts. Wait a minute, then try again.'
                            : 'Could not confirm your password. Reload the page and try again.',
                );

                return false;
            }

            setNeedsConfirmation(false);
        } catch {
            setError(
                'Could not confirm your password. Check your connection and try again.',
            );

            return false;
        } finally {
            setProcessing(false);
        }

        await reveal();

        return true;
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
