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
import type { HttpMethod } from '@/types/app';

type PendingVisit = {
    url: string;
    method: HttpMethod;
    data: Record<string, unknown>;
    /** A file: after confirming, the browser navigates to it instead of an Inertia visit. */
    download?: boolean;
    /** The login has no password: offer "Set a password" instead of the password field. */
    noPassword?: boolean;
};

/**
 * Step-up authentication for sensitive actions. When the server answers a visit with a
 * "password_confirmation" error (the `password.recent` middleware), ask for the password,
 * confirm it with Fortify, then repeat the original request once.
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
                setPending({
                    ...lastVisit.current,
                    noPassword:
                        event.detail.errors.step_up_reason ===
                        'password_not_set',
                });
            }
        });

        const onDownload = (event: Event) => {
            event.preventDefault();
            setPassword('');
            setError(null);
            const { url, noPassword } = (
                event as CustomEvent<{ url: string; noPassword?: boolean }>
            ).detail;
            setPending({
                url,
                method: 'get',
                data: {},
                download: true,
                noPassword,
            });
        };
        window.addEventListener(STEP_UP_DOWNLOAD_EVENT, onDownload);

        return () => {
            offBefore();
            offError();
            window.removeEventListener(STEP_UP_DOWNLOAD_EVENT, onDownload);
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
                setError(
                    response.status === 422
                        ? 'The password is incorrect.'
                        : 'Could not confirm your password. Try again.',
                );

                return;
            }

            const visit = pending;
            setPending(null);

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

    const noPassword = pending?.noPassword === true || !hasPassword;

    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && !processing && setPending(null)}
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
                                onClick={() => setPending(null)}
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
                                to continue; you won't be asked again for 15
                                minutes.
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
                                onClick={() => setPending(null)}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || password === ''}
                            >
                                {processing && <Spinner />}
                                Confirm and continue
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
