import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { httpFailure } from '@/lib/http-error';
import { setLink } from '@/routes/password';

/**
 * For a login without a password: asks the server to email a link to the set-password page.
 */
export function SetPasswordButton({ className }: { className?: string }) {
    const [state, setState] = useState<'idle' | 'sending' | 'sent'>('idle');
    const [problem, setProblem] = useState<string | null>(null);

    const { post } = useHttp();

    const send = async () => {
        setState('sending');
        setProblem(null);

        try {
            await post(setLink.url());
            setState('sent');
        } catch (error) {
            const failure = httpFailure(error);

            setState('idle');
            setProblem(
                !failure
                    ? 'Could not send the email. Check your connection and try again.'
                    : failure.status === 403
                      ? failure.body.code === 'password_already_set'
                          ? 'Your account already has a password.'
                          : 'Verify your email first, then ask for the link again.'
                      : failure.status === 429
                        ? "You've asked several times; try again in a few minutes."
                        : 'Could not send the email. Try again in a moment.',
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
