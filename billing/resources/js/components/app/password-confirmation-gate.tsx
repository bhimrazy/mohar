import { router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import ConfirmablePasswordController from '@/actions/Laravel/Fortify/Http/Controllers/ConfirmablePasswordController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    SetPasswordButton,
    xsrfToken,
} from '@/components/app/set-password-button';
import { STEP_UP_DOWNLOAD_EVENT } from '@/components/app/step-up-download-link';
import {
    inertiaStepUpRefusal,
    replacePending,
    stepUpWindow,
    STEP_UP_CONFIRM_EVENT,
} from '@/lib/step-up';
import type { StepUpConfirmDetail } from '@/lib/step-up';
import type { HttpMethod } from '@/types/app';

type PendingVisit = {
    url: string;
    method: HttpMethod;
    data: Record<string, unknown>;
    /** A file: after confirming, the browser navigates to it instead of an Inertia visit. */
    download?: boolean;
    /** The login has no password: offer "Set a password" instead of the password field. */
    noPassword?: boolean;
    /** The caller retries itself (requestStepUp): told the outcome instead of a replayed visit. */
    resolve?: (confirmed: boolean) => void;
};

/**
 * Step-up authentication for sensitive actions. When the server refuses a visit for step-up (`password.recent`, or
 * `password.confirm` for anything but a page load: a 423 with the step-up header, or a "password_confirmation" error
 * from a check in a form request), ask for the password, confirm it with Fortify, then repeat the original request
 * once. A caller that retries itself asks through
 * `requestStepUp()` and is told whether the password was confirmed.
 */
export function PasswordConfirmationGate() {
    const { auth } = usePage().props;
    const hasPassword = auth.user?.has_password !== false;
    const lastVisit = useRef<PendingVisit | null>(null);
    const [pending, setPending] = useState<PendingVisit | null>(null);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        const offBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            lastVisit.current = {
                url: visit.url.href,
                method: visit.method as HttpMethod,
                data:
                    visit.data instanceof FormData
                        ? Object.fromEntries(visit.data.entries())
                        : (visit.data as Record<string, unknown>),
            };
        });
        const offError = router.on('error', (event) => {
            if (
                event.detail.errors.password_confirmation &&
                lastVisit.current &&
                lastVisit.current.method !== 'get'
            ) {
                setPassword('');
                setError(null);
                const visit: PendingVisit = {
                    ...lastVisit.current,
                    noPassword:
                        event.detail.errors.step_up_reason ===
                        'password_not_set',
                };
                setPending((previous) => replacePending(previous, visit));
            }
        });

        // The server's answer to a refused Inertia mutation (#538): a 423 with the step-up header. Nothing was changed
        // and no page reloads, so the form keeps its input; confirm, then send the same visit again.
        const offHttpException = router.on('httpException', (event) => {
            const refusal = inertiaStepUpRefusal(event.detail.response);

            if (
                !refusal ||
                !lastVisit.current ||
                lastVisit.current.method === 'get'
            ) {
                return;
            }

            event.preventDefault();
            setPassword('');
            setError(null);
            const visit: PendingVisit = {
                ...lastVisit.current,
                noPassword: refusal.noPassword,
            };
            setPending((previous) => replacePending(previous, visit));
        });

        const onDownload = (event: Event) => {
            event.preventDefault();
            setPassword('');
            setError(null);
            const { url, noPassword } = (
                event as CustomEvent<{ url: string; noPassword?: boolean }>
            ).detail;
            setPending((previous) =>
                replacePending(previous, {
                    url,
                    method: 'get',
                    data: {},
                    download: true,
                    noPassword,
                }),
            );
        };
        const onConfirm = (event: Event) => {
            event.preventDefault();
            setPassword('');
            setError(null);
            const { noPassword, resolve } = (
                event as CustomEvent<StepUpConfirmDetail>
            ).detail;
            setPending((previous) =>
                replacePending(previous, {
                    url: '',
                    method: 'get',
                    data: {},
                    noPassword,
                    resolve,
                }),
            );
        };
        window.addEventListener(STEP_UP_DOWNLOAD_EVENT, onDownload);
        window.addEventListener(STEP_UP_CONFIRM_EVENT, onConfirm);

        return () => {
            offBefore();
            offError();
            offHttpException();
            window.removeEventListener(STEP_UP_DOWNLOAD_EVENT, onDownload);
            window.removeEventListener(STEP_UP_CONFIRM_EVENT, onConfirm);
        };
    }, []);

    const confirm = async (event: FormEvent) => {
        event.preventDefault();

        if (!pending) {
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            const response = await fetch(
                ConfirmablePasswordController.store.url(),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                    body: JSON.stringify({ password }),
                },
            );

            if (!response.ok) {
                // A 429 carries the wait ("Try again in n seconds").
                const throttled =
                    response.status === 429
                        ? ((await response.json().catch(() => null)) as {
                              message?: string;
                              signed_out?: boolean;
                              redirect?: string;
                          } | null)
                        : null;

                // The last allowed wrong password ended the session: sign-in shows why.
                if (throttled?.signed_out && throttled.redirect) {
                    window.location.assign(throttled.redirect);

                    return;
                }

                setError(
                    response.status === 422
                        ? 'The password is incorrect.'
                        : response.status === 429
                          ? (throttled?.message ??
                            'Too many password attempts. Try again in a minute.')
                          : 'Could not confirm your password. Try again.',
                );

                return;
            }

            const visit = pending;
            setPending(null);

            if (visit.resolve) {
                visit.resolve(true);

                return;
            }

            if (visit.download) {
                window.location.assign(visit.url);

                return;
            }

            router.visit(visit.url, {
                method: visit.method,
                data: visit.data as Record<string, string>,
                preserveScroll: true,
            });
        } catch {
            setError(
                'Could not confirm your password. Check your connection and try again.',
            );
        } finally {
            setProcessing(false);
        }
    };

    // The server's answer wins; the page prop can be stale (a password set in another window).
    const noPassword = pending?.noPassword ?? !hasPassword;

    const dismiss = () => {
        pending?.resolve?.(false);
        setPending(null);
    };

    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && !processing && dismiss()}
        >
            <DialogContent className="sm:max-w-md">
                {noPassword ? (
                    <div className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>
                                Set a password to continue
                            </DialogTitle>
                            <DialogDescription>
                                This is a sensitive action and your account has
                                no password yet, so it can't be confirmed. We
                                can email you a link to set one; then try again.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={dismiss}
                            >
                                Cancel
                            </Button>
                            <SetPasswordButton />
                        </DialogFooter>
                    </div>
                ) : (
                    <form onSubmit={confirm} className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>Confirm your password</DialogTitle>
                            <DialogDescription>
                                This is a sensitive action. Enter your password
                                to continue; you won't be asked again for{' '}
                                {stepUpWindow(auth.step_up_minutes)}.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="step-up-password">Password</Label>
                            <PasswordInput
                                id="step-up-password"
                                autoFocus
                                autoComplete="current-password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                aria-invalid={Boolean(error)}
                            />
                            <InputError message={error ?? undefined} />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={dismiss}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || password === ''}
                            >
                                {processing && <Spinner />}
                                Confirm password
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
