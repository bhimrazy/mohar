import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ClientLifecycleController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientLifecycleController';
import { Field } from '@/components/app/field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type Preview = {
    target: {
        id: number;
        name: string;
        currency: string;
        status: string;
    } | null;
    counts: Record<string, number>;
};

type Props = {
    client: { id: number; display_name: string; currency: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const label = (table: string) => table.replace(/_/g, ' ');

export function MergeDialog({ client, open, onOpenChange }: Props) {
    const [targetId, setTargetId] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open || !/^\d+$/.test(targetId)) {
            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(() => {
            fetch(
                ClientLifecycleController.mergePreview.url(client.id, {
                    query: { target_id: targetId },
                }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            )
                .then((response) => response.json())
                .then((data: Preview) => setPreview(data))
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [open, targetId, client.id]);

    const target = /^\d+$/.test(targetId) ? preview?.target : null;
    const mismatch = target ? target.currency !== client.currency : false;

    const merge = () => {
        router.visit(ClientLifecycleController.merge.url(client.id), {
            method: 'post',
            data: { target_id: targetId },
            onStart: () => {
                setProcessing(true);
                setError(null);
            },
            onError: (errors) =>
                setError(Object.values(errors)[0] ?? 'Something went wrong.'),
            onSuccess: () => onOpenChange(false),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !processing && onOpenChange(next)}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Merge {client.display_name} into another client?
                    </DialogTitle>
                    <DialogDescription>
                        Services, domains, invoices, contacts and other records
                        move to the target client. Logins become non-owner users
                        of the target. This client is closed. This can't be
                        undone.
                    </DialogDescription>
                </DialogHeader>
                <Field
                    htmlFor="merge-target"
                    label="Target client ID"
                    hint="Both clients must use the same currency."
                >
                    <Input
                        id="merge-target"
                        inputMode="numeric"
                        value={targetId}
                        onChange={(event) =>
                            setTargetId(event.target.value.trim())
                        }
                        placeholder="e.g. 1042"
                    />
                </Field>
                {target ? (
                    <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                        <p className="font-medium">
                            #{target.id} {target.name}{' '}
                            <span className="font-mono text-xs text-muted-foreground">
                                {target.currency}
                            </span>
                        </p>
                        {mismatch && (
                            <p className="mt-1 text-destructive">
                                Currencies differ ({client.currency} vs{' '}
                                {target.currency}); merging is blocked.
                            </p>
                        )}
                        {preview && Object.keys(preview.counts).length > 0 && (
                            <ul className="mt-2 grid grid-cols-2 gap-x-4 text-muted-foreground">
                                {Object.entries(preview.counts).map(
                                    ([table, count]) => (
                                        <li key={table} className="capitalize">
                                            {label(table)}:{' '}
                                            <span className="text-foreground tabular-nums">
                                                {count}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                    </div>
                ) : (
                    targetId !== '' &&
                    preview && (
                        <p className="text-sm text-muted-foreground">
                            No client with that ID.
                        </p>
                    )
                )}
                <InputError message={error ?? undefined} />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={merge}
                        disabled={!target || mismatch || processing}
                    >
                        {processing && <Spinner />}
                        Merge client
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
