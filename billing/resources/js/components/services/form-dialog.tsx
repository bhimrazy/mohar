import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type FormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    children: ReactNode;
    submitLabel: string;
    processing: boolean;
    destructive?: boolean;
    onSubmit: () => void;
};

/** Dialog with a small form (reason, new password, status) and Cancel / confirm buttons. */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
    submitLabel,
    processing,
    destructive = false,
    onSubmit,
}: FormDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !processing && onOpenChange(next)}
        >
            <DialogContent
                {...(description ? {} : { 'aria-describedby': undefined })}
            >
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </DialogHeader>
                    {children}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={processing}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
