import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SetPasswordButton } from '@/components/app/set-password-button';
import { Field } from '@/components/app/field';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { FormDialog } from '@/components/services/form-dialog';
import { useCredentialsReveal } from '@/components/services/use-credentials-reveal';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** How long a revealed password stays on screen (#44). */
const VISIBLE_SECONDS = 60;

/**
 * The hosting password row of the client service page: hidden until asked for, then fetched
 * from the step-up protected endpoint, shown for 60 seconds with a copy button.
 */
export function PasswordReveal({ url }: { url: string }) {
    const credentials = useCredentialsReveal(url, {
        hideAfterSeconds: VISIBLE_SECONDS,
    });
    const [accountPassword, setAccountPassword] = useState('');
    const [copied, setCopied] = useState(false);
    const shown = credentials.password !== null;

    useEffect(() => {
        if (!shown) {
            setCopied(false);
        }
    }, [shown]);

    const copy = async () => {
        if (credentials.password === null || !navigator?.clipboard) {
            return;
        }

        try {
            await navigator.clipboard.writeText(credentials.password);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="grid gap-1.5">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono break-all">
                    {credentials.password ?? '••••••••••••'}
                </span>
                {shown && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => void copy()}
                    >
                        {copied ? <Check /> : <Copy />}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={credentials.processing}
                    onClick={() =>
                        shown ? credentials.hide() : void credentials.reveal()
                    }
                >
                    {shown ? <EyeOff /> : <Eye />}
                    {shown ? 'Hide password' : 'Show password'}
                </Button>
            </div>
            {shown && credentials.secondsLeft !== null && (
                <p className="text-xs text-muted-foreground" aria-live="polite">
                    Hidden again in {credentials.secondsLeft} s.
                </p>
            )}
            {!credentials.needsConfirmation && (
                <InputError message={credentials.error ?? undefined} />
            )}

            <FormDialog
                open={credentials.needsConfirmation && !credentials.noPassword}
                onOpenChange={(open) =>
                    !open && credentials.cancelConfirmation()
                }
                title="Confirm your password"
                description="Showing your hosting password needs your account password. Each time it is shown is recorded."
                submitLabel="Confirm and show"
                processing={credentials.processing}
                disabled={accountPassword === ''}
                onSubmit={() => {
                    void credentials
                        .confirm(accountPassword)
                        .then(() => setAccountPassword(''));
                }}
            >
                <Field
                    htmlFor="reveal-step-up-password"
                    label="Your account password"
                    error={credentials.error ?? undefined}
                >
                    <PasswordInput
                        id="reveal-step-up-password"
                        autoFocus
                        autoComplete="current-password"
                        value={accountPassword}
                        onChange={(event) =>
                            setAccountPassword(event.target.value)
                        }
                        aria-invalid={Boolean(credentials.error)}
                    />
                </Field>
            </FormDialog>

            <Dialog
                open={credentials.needsConfirmation && credentials.noPassword}
                onOpenChange={(open) =>
                    !open && credentials.cancelConfirmation()
                }
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Set a password to continue</DialogTitle>
                        <DialogDescription>
                            Showing your hosting password needs your account
                            password, and your account has none yet. We can
                            email you a link to set one; then try again.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => credentials.cancelConfirmation()}
                        >
                            Cancel
                        </Button>
                        <SetPasswordButton />
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
