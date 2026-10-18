import { useState } from 'react';
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
 * answers 423 (no recent password confirmation), ask for the staff password, confirm it with
 * Fortify and retry once. The password lives only in component state, never in Inertia props.
 */
export function useCredentialsReveal(url: string) {
    const [password, setPassword] = useState<string | null>(null);
    const [needsConfirmation, setNeedsConfirmation] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const reveal = async () => {
        setProcessing(true);
        setError(null);

        try {
            const response = await postJson(url);

            if (response.status === 423) {
                setNeedsConfirmation(true);

                return;
            }

            if (!response.ok) {
                setError('Could not reveal the password.');

                return;
            }

            const data = (await response.json()) as { password: string | null };
            setPassword(data.password);
        } finally {
            setProcessing(false);
        }
    };

    const confirm = async (staffPassword: string) => {
        setProcessing(true);
        setError(null);

        try {
            const response = await postJson(
                ConfirmablePasswordController.store.url(),
                { password: staffPassword },
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
        hide: () => setPassword(null),
        reveal,
        confirm,
        needsConfirmation,
        cancelConfirmation: () => setNeedsConfirmation(false),
        processing,
        error,
    };
}
