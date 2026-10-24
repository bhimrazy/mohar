import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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

/** How long a revealed password stays on screen (#44), in the client area and the admin area alike. */
const VISIBLE_SECONDS = 60;

type Props = {
    /** The step-up protected endpoint that answers `{ password }`. */
    url: string;
    /** Why the password can't be shown here (staff signed in as the client): the button is disabled with it. */
    disabledReason?: string | null;
};

/**
 * A service password row: hidden until asked for, then fetched from the step-up protected endpoint and shown for 60
 * seconds with a copy button. Used on the client and the admin service pages, so both use the same words.
 */
export function PasswordReveal({ url, disabledReason = null }: Props) {
    const credentials = useCredentialsReveal(url, {
        hideAfterSeconds: VISIBLE_SECONDS,
    });
    const [accountPassword, setAccountPassword] = useState('');
    const [copied, setCopied] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const [hiddenOnce, setHiddenOnce] = useState(false);
    const shown = credentials.password !== null;
    const wasShown = useRef(false);

    useEffect(() => {
        if (shown) {
            wasShown.current = true;
            setAnnouncement(
                `Password shown. Hidden again in ${VISIBLE_SECONDS} seconds.`,
            );
        } else if (wasShown.current) {
            setCopied(false);
            setHiddenOnce(true);
            setAnnouncement('Password hidden.');
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

    const status = shown
        ? `Hidden again in ${credentials.secondsLeft ?? VISIBLE_SECONDS} seconds.`
        : disabledReason
          ? disabledReason
          : hiddenOnce
            ? 'Hidden. Show it again if you need it.'
            : `Shown for ${VISIBLE_SECONDS} seconds after you confirm your password.`;

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
                    disabled={credentials.processing || Boolean(disabledReason)}
                    aria-describedby={
                        disabledReason ? 'password-reveal-status' : undefined
                    }
                    onClick={() =>
                        shown ? credentials.hide() : void credentials.reveal()
                    }
                >
                    {shown ? <EyeOff /> : <Eye />}
                    {shown ? 'Hide' : 'Show password'}
                </Button>
            </div>
            {/* Always rendered, so the row keeps its height when the password appears or hides. */}
            <p
                id="password-reveal-status"
                className="min-h-4 text-xs text-muted-foreground"
            >
                {status}
            </p>
            <span className="sr-only" aria-live="polite">
                {announcement}
            </span>
            {!credentials.needsConfirmation && (
                <InputError message={credentials.error ?? undefined} />
            )}

            <FormDialog
                open={credentials.needsConfirmation && !credentials.noPassword}
                onOpenChange={(open) => {
                    if (!open) {
                        credentials.cancelConfirmation();
                        setAccountPassword('');
                    }
                }}
                title="Confirm your password"
                description="Showing this password needs your account password. Each time it is shown is recorded in the activity log."
                submitLabel="Confirm and show"
                processing={credentials.processing}
                disabled={accountPassword === ''}
                onSubmit={() => {
                    void credentials
                        .confirm(accountPassword)
                        .then((accepted) => accepted && setAccountPassword(''));
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
                            Showing this password needs your account password,
                            and your account has none yet. We can email you a
                            link to set one; then try again.
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
