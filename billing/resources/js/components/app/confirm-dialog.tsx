import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { HttpMethod, RouteAction } from '@/types/app';

type ConfirmDialogProps = {
    /** Element that opens the dialog (rendered with `asChild`). */
    trigger?: ReactNode;
    /** Controlled mode (e.g. opened from a dropdown menu item). */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;

    /** Question form: "Delete invoice INV-1042?" */
    title: string;
    /** Consequence, in plain words: what happens and whether it can be undone. */
    description?: ReactNode;
    /** Verb + noun, matching the title: "Delete invoice". */
    confirmLabel: string;
    cancelLabel?: string;
    /** Red confirm button. Default true; set false for non-destructive confirms. */
    destructive?: boolean;
    /** Require the user to type this exact text (irreversible actions). */
    confirmText?: string;

    /** Wayfinder action, e.g. `InvoiceController.destroy(invoice)`. */
    action?: RouteAction;
    /** Alternative to `action`. */
    href?: string;
    method?: HttpMethod;
    data?: Record<string, string | number | boolean | null>;
    /** Runs after a successful request (dialog closes automatically). */
    onSuccess?: () => void;
    /** Custom handler instead of an Inertia request. */
    onConfirm?: () => void;
};

export function ConfirmDialog({
    trigger,
    open: controlledOpen,
    onOpenChange,
    title,
    description,
    confirmLabel,
    cancelLabel = 'Cancel',
    destructive = true,
    confirmText,
    action,
    href,
    method = 'delete',
    data,
    onSuccess,
    onConfirm,
}: ConfirmDialogProps) {
    const [internalOpen, setInternalOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [typed, setTyped] = useState('');

    const open = controlledOpen ?? internalOpen;

    const setOpen = (next: boolean) => {
        if (processing) {
            return;
        }

        if (!next) {
            setError(null);
            setTyped('');
        }

        setInternalOpen(next);
        onOpenChange?.(next);
    };

    const canConfirm = !confirmText || typed.trim() === confirmText;

    const confirm = () => {
        if (!canConfirm) {
            return;
        }

        if (onConfirm) {
            onConfirm();
            setOpen(false);

            return;
        }

        const url = action?.url ?? href;

        if (!url) {
            return;
        }

        router.visit(url, {
            method: action?.method ?? method,
            data: data ?? {},
            preserveScroll: true,
            onStart: () => {
                setError(null);
                setProcessing(true);
            },
            onSuccess: () => {
                setProcessing(false);
                setOpen(false);
                onSuccess?.();
            },
            onError: (errors) => {
                setError(Object.values(errors)[0] ?? 'Something went wrong.');
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            {trigger && (
                <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
            )}
            <AlertDialogContent
                {...(description ? {} : { 'aria-describedby': undefined })}
            >
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    {description && (
                        <AlertDialogDescription>
                            {description}
                        </AlertDialogDescription>
                    )}
                </AlertDialogHeader>

                {confirmText && (
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-dialog-text">
                            Type{' '}
                            <span className="font-mono font-semibold">
                                {confirmText}
                            </span>{' '}
                            to confirm
                        </Label>
                        <Input
                            id="confirm-dialog-text"
                            value={typed}
                            onChange={(event) => setTyped(event.target.value)}
                            autoComplete="off"
                            spellCheck={false}
                        />
                    </div>
                )}

                <InputError message={error ?? undefined} />

                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>
                        {cancelLabel}
                    </AlertDialogCancel>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={!canConfirm || processing}
                        onClick={confirm}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
