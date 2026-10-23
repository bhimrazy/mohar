import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { setLink } from '@/routes/password';

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * For a login without a password: asks the server to email a link to set one. The reset page is for guests only, so
 * the text says to open the link in a private window.
 */
export function SetPasswordButton({ className }: { className?: string }) {
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>(
        'idle',
    );

    const send = async () => {
        setState('sending');

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

            setState(response.ok ? 'sent' : 'failed');
        } catch {
            setState('failed');
        }
    };

    if (state === 'sent') {
        return (
            <p
                role="status"
                className="text-sm text-muted-foreground"
                data-test="set-password-sent"
            >
                Check your email. The link lets you choose a password; open it
                in a private window if you stay signed in here.
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
                Set a password
            </Button>
            {state === 'failed' && (
                <p role="alert" className="text-sm text-destructive">
                    Could not send the email. Try again in a moment.
                </p>
            )}
        </div>
    );
}
