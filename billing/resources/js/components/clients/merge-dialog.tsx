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
    problem: string | null;
    items: {
        key: string;
        label: string;
        count: number;
        blocking: string | null;
    }[];
};

type Props = {
    client: { id: number; display_name: string; currency: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const CONFIRM_WORD = 'MERGE';

export function MergeDialog({ client, open, onOpenChange }: Props) {
    const [targetId, setTargetId] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const validId = /^\d+$/.test(targetId);

    useEffect(() => {
        if (!open || !validId) {
            setPreview(null);

            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(() => {
            setLoading(true);
            fetch(
                ClientLifecycleController.mergePreview.url(client.id, {
                    query: { target_id: targetId },
                }),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            )
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(
                            response.status === 403
                                ? 'You are not allowed to merge clients.'
                                : 'Could not load the merge preview.',
                        );
                    }

                    return (await response.json()) as Preview;
                })
                .then((data) => {
                    setError(null);
                    setPreview(data);
                })
                .catch((reason: unknown) => {
                    if (
                        !(
                            reason instanceof DOMException &&
                            reason.name === 'AbortError'
                        )
                    ) {
                        setPreview(null);
                        setError(
                            reason instanceof Error
                                ? reason.message
                                : 'Could not load the merge preview.',
                        );
                    }
                })
                .finally(() => setLoading(false));
        }, 300);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [open, targetId, validId, client.id]);

    const close = (next: boolean) => {
        if (processing) {
            return;
        }

        if (!next) {
            setTargetId('');
            setConfirmation('');
            setPreview(null);
            setError(null);
        }

        onOpenChange(next);
    };

    const canMerge =
        Boolean(preview?.target) &&
        !preview?.problem &&
        confirmation.trim() === CONFIRM_WORD &&
        !processing;
    const moving = (preview?.items ?? []).filter((item) => item.count > 0);

    const merge = () => {
        router.visit(ClientLifecycleController.merge.url(client.id), {
            method: 'post',
            data: { target_id: targetId, confirmation: confirmation.trim() },
            onStart: () => {
                setProcessing(true);
                setError(null);
            },
            onError: (errors) => {
                if (!errors.password_confirmation) {
                    setError(
                        Object.values(errors)[0] ?? 'Something went wrong.',
                    );
                }
            },
            onSuccess: () => close(false),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Merge {client.display_name} into another client?
                    </DialogTitle>
                    <DialogDescription>
                        Contacts, logins and every module's records (services,
                        domains, invoices, tickets…) move to the target client.
                        This client is closed and its profile redirects to the
                        target. This can't be undone.
                    </DialogDescription>
                </DialogHeader>
                <Field
                    htmlFor="merge-target"
                    label="Target client ID"
                    hint="Find the ID with ⌘K search. Both clients must use the same currency."
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

                {validId && preview && (
                    <div
                        className="space-y-2 rounded-lg border bg-muted/40 p-3 text-sm"
                        aria-live="polite"
                    >
                        {preview.target && (
                            <p className="font-medium">
                                #{preview.target.id} {preview.target.name}{' '}
                                <span className="font-mono text-xs text-muted-foreground">
                                    {preview.target.currency}
                                </span>
                            </p>
                        )}
                        {preview.problem ? (
                            <p className="text-destructive">
                                {preview.problem}
                            </p>
                        ) : moving.length > 0 ? (
                            <ul className="grid grid-cols-2 gap-x-4 text-muted-foreground">
                                {moving.map((item) => (
                                    <li key={item.key}>
                                        {item.label}:{' '}
                                        <span className="text-foreground tabular-nums">
                                            {item.count}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground">
                                Nothing else is attached to this client; only
                                the profile is closed.
                            </p>
                        )}
                    </div>
                )}
                {loading && !preview && (
                    <p className="text-sm text-muted-foreground">Checking…</p>
                )}

                <Field
                    htmlFor="merge-confirm"
                    label={
                        <>
                            Type{' '}
                            <span className="font-mono font-semibold">
                                {CONFIRM_WORD}
                            </span>{' '}
                            to confirm
                        </>
                    }
                >
                    <Input
                        id="merge-confirm"
                        value={confirmation}
                        onChange={(event) =>
                            setConfirmation(event.target.value)
                        }
                        autoComplete="off"
                        spellCheck={false}
                    />
                </Field>
                <InputError message={error ?? undefined} />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => close(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={merge}
                        disabled={!canMerge}
                    >
                        {processing && <Spinner />}
                        Merge client
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
