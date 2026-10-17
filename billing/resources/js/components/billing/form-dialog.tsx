import { useForm } from '@inertiajs/react';
import type { FormDataConvertible, FormDataType } from '@inertiajs/core';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { RouteAction } from '@/types/app';

export type DialogForm<T extends FormDataType<T>> = ReturnType<
    typeof useForm<T>
>;

type FormDialogProps<T extends FormDataType<T>> = {
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    submitLabel: string;
    action: RouteAction;
    initial: T;
    destructive?: boolean;
    /** Reshape the data right before submitting (e.g. parse a comma-separated list). */
    transform?: (data: T) => Record<string, FormDataConvertible>;
    children: (form: DialogForm<T>) => ReactNode;
};

/**
 * A small form in a dialog (record payment, refund, apply credit…). Field errors render under fields;
 * rule violations from the server (`errors.billing`) render above the buttons.
 */
export function FormDialog<T extends FormDataType<T>>({
    trigger,
    open: controlledOpen,
    onOpenChange,
    title,
    description,
    submitLabel,
    action,
    initial,
    destructive = false,
    transform,
    children,
}: FormDialogProps<T>) {
    const [internalOpen, setInternalOpen] = useState(false);
    const form = useForm<T>(initial);
    const open = controlledOpen ?? internalOpen;
    const errors = form.errors as Record<string, string | undefined>;

    const setOpen = (next: boolean) => {
        if (form.processing) {
            return;
        }

        if (!next) {
            form.reset();
            form.clearErrors();
        }

        setInternalOpen(next);
        onOpenChange?.(next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (transform) {
            form.transform(transform);
        }

        form.submit(action, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </DialogHeader>
                    {children(form)}
                    <InputError message={errors.billing} />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
