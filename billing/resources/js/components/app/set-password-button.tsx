import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { setLink } from '@/routes/password';

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * For a login without a password: asks the server to email a link to the set-password page.
 */
export function SetPasswordButton({ className }: { className?: string }) {
    const [state, setState] = useState<'idle' | 'sending' | 'sent'>('idle');
    const [problem, setProblem] = useState<string | null>(null);

    const send = async () => {
        setState('sending');
        setProblem(null);

        try {
            const response = await fetch(setLink.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });

            if (response.ok) {
                setState('sent');

                return;
            }

            const body = await response.json().catch(() => null);

            setState('idle');
            setProblem(
                response.status === 403
                    ? body?.code === 'password_already_set'
                        ? 'Your account already has a password.'
                        : 'Verify your email first, then ask for the link again.'
                    : response.status === 429
                      ? "You've asked several times; try again in a few minutes."
                      : 'Could not send the email. Try again in a moment.',
            );
        } catch {
            setState('idle');
            setProblem(
                'Could not send the email. Check your connection and try again.',
            );
        }
    };

    if (state === 'sent') {
        return (
            <p
                role="status"
                className="text-sm text-muted-foreground"
                data-test="set-password-sent"
            >
                Check your email. The link opens a page where you choose a
                password.
            </p>
        );
    }

    return (
        <div className="grid gap-2">
            <Button
                type="button"
                className={className}
                onClick={send}
                disabled={state === 'sending'}
                data-test="set-password-button"
            >
                {state === 'sending' && <Spinner />}
                Email me a link
            </Button>
            {problem && (
                <p role="alert" className="text-sm text-destructive">
                    {problem}
                </p>
            )}
        </div>
    );
}
