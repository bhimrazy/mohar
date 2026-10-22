import { ImageIcon, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Field } from '@/components/app/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, resizeImage } from '@/lib/image-resize';
import type { ResizeResult } from '@/lib/image-resize';

type Props = {
    id: string;
    name: string;
    label: string;
    accept: string;
    hint?: string;
    error?: string;
    onBusyChange?: (busy: boolean) => void;
};

type State =
    | { kind: 'empty' }
    | { kind: 'working' }
    | { kind: 'ready'; result: ResizeResult; previewUrl: string };

const KEPT: Record<string, string> = {
    small: 'Small enough, uploaded as it is.',
    'not-smaller': 'Already compact, uploaded as it is.',
    unsupported:
        'Your browser could not shrink this photo, so it is uploaded as it is. Our server will check it.',
    failed: 'Your browser could not shrink this photo, so it is uploaded as it is. Our server will check it.',
};

/**
 * File input with a browser-side resize and a preview. The resized file replaces the chosen one inside the same
 * input, so the surrounding form posts it unchanged.
 */
export function PhotoField({
    id,
    name,
    label,
    accept,
    hint,
    error,
    onBusyChange,
}: Props) {
    const input = useRef<HTMLInputElement>(null);
    const run = useRef(0);
    const [state, setState] = useState<State>({ kind: 'empty' });
    const statusId = useId();

    useEffect(() => {
        onBusyChange?.(state.kind === 'working');
    }, [state.kind, onBusyChange]);

    useEffect(() => {
        const previewUrl = state.kind === 'ready' ? state.previewUrl : null;

        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [state]);

    // The form resets the input after a successful upload; clear the preview with it.
    useEffect(() => {
        const form = input.current?.form;
        const clear = () => {
            run.current++;
            setState({ kind: 'empty' });
        };

        form?.addEventListener('reset', clear);

        return () => form?.removeEventListener('reset', clear);
    }, []);

    async function choose(file: File | undefined) {
        const token = ++run.current;

        if (!file) {
            setState({ kind: 'empty' });

            return;
        }

        setState({ kind: 'working' });

        const result = await resizeImage(file);

        if (token !== run.current || !input.current) {
            return;
        }

        if (result.resized) {
            const transfer = new DataTransfer();
            transfer.items.add(result.file);
            input.current.files = transfer.files;
        }

        setState({
            kind: 'ready',
            result,
            previewUrl: URL.createObjectURL(result.file),
        });
    }

    function remove() {
        run.current++;

        if (input.current) {
            input.current.value = '';
            input.current.focus();
        }

        setState({ kind: 'empty' });
    }

    return (
        <Field htmlFor={id} label={label} hint={hint} error={error}>
            <Input
                ref={input}
                id={id}
                name={name}
                type="file"
                accept={accept}
                aria-invalid={!!error}
                aria-describedby={`${id}-description ${statusId}`}
                onChange={(event) => void choose(event.target.files?.[0])}
            />
            <div id={statusId} role="status" aria-live="polite">
                {state.kind === 'working' && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner /> Preparing your photo…
                    </p>
                )}
                {state.kind === 'ready' && (
                    <div className="flex items-start gap-3 rounded-md border bg-muted/40 p-2">
                        <img
                            src={state.previewUrl}
                            alt={`Preview of the ${label.toLowerCase()} photo`}
                            className="size-20 shrink-0 rounded-sm bg-background object-cover"
                        />
                        <div className="grid min-w-0 gap-1 text-sm">
                            <p className="flex items-center gap-1.5 font-medium">
                                <ImageIcon
                                    className="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span className="truncate">
                                    {state.result.file.name}
                                </span>
                            </p>
                            <p className="text-muted-foreground">
                                {state.result.resized
                                    ? `Reduced from ${formatBytes(state.result.original.bytes)} (${state.result.original.width} × ${state.result.original.height}) to ${formatBytes(state.result.output.bytes)} (${state.result.output.width} × ${state.result.output.height}).`
                                    : `${formatBytes(state.result.file.size)}. ${KEPT[state.result.reason ?? 'failed']}`}
                            </p>
                            <div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={remove}
                                >
                                    <X aria-hidden="true" />
                                    Remove and choose another
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </Field>
    );
}
