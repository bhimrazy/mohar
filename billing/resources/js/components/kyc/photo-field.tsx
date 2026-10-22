import { ImageIcon, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Field } from '@/components/app/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, prepareFile } from '@/lib/image-resize';
import type { ResizeResult } from '@/lib/image-resize';

type Props = {
    id: string;
    name: string;
    label: string;
    accept: string;
    error?: string;
    /** The server's size limit in MB; a photo over it that cannot be made smaller is refused here. */
    maxUploadMb: number;
    onBusyChange?: (busy: boolean) => void;
};

type State =
    | { kind: 'empty' }
    | { kind: 'working' }
    | { kind: 'refused'; name: string }
    | { kind: 'toolarge'; name: string; bytes: number }
    | { kind: 'ready'; result: ResizeResult; previewUrl: string | null };

const KEPT: Record<string, string> = {
    small: 'This photo is small enough, so it is sent as it is.',
    'not-smaller': 'This photo is already small, so it is sent as it is.',
    unsupported:
        'Your browser cannot make this photo smaller. It is sent as it is and checked when you upload.',
    failed: 'Making this photo smaller did not work. It is sent as it is and checked when you upload.',
};

const IMAGE_TYPE = /^image\/(jpeg|png|webp)$/;

/**
 * File input with a browser-side resize and a preview. The resized file replaces the chosen one inside the same
 * input, so the surrounding form posts it unchanged.
 */
export function PhotoField({
    id,
    name,
    label,
    accept,
    error,
    maxUploadMb,
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

        if (!IMAGE_TYPE.test(file.type)) {
            // Not a JPEG, PNG or WebP photo (a HEIC from an iPhone, a PDF): the server would refuse it after the wait.
            if (input.current) {
                input.current.value = '';
            }

            setState({ kind: 'refused', name: file.name });

            return;
        }

        setState({ kind: 'working' });

        let result: ResizeResult | null = null;

        try {
            result = await prepareFile(file, (resized) => {
                const transfer = new DataTransfer();
                transfer.items.add(resized);

                if (input.current) {
                    input.current.files = transfer.files;
                }
            });
        } finally {
            if (token === run.current) {
                if (!result || result.file.size > maxUploadMb * 1024 * 1024) {
                    // Nothing usable, or still over the server limit: do not make the client wait for a refusal.
                    if (input.current) {
                        input.current.value = '';
                    }

                    setState(
                        result
                            ? {
                                  kind: 'toolarge',
                                  name: file.name,
                                  bytes: result.file.size,
                              }
                            : { kind: 'empty' },
                    );
                } else {
                    let previewUrl: string | null = null;

                    try {
                        previewUrl = URL.createObjectURL(result.file);
                    } catch {
                        // The preview is optional; the upload still works.
                    }

                    setState({ kind: 'ready', result, previewUrl });
                }
            }
        }
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
        <Field
            htmlFor={id}
            label={label}
            error={error}
            className="min-w-0 grid-cols-[minmax(0,1fr)]"
        >
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
            <p role="status" className="sr-only">
                {state.kind === 'working' && 'Preparing your photo.'}
                {state.kind === 'refused' &&
                    `${state.name} is not a JPEG, PNG or WebP photo.`}
                {state.kind === 'toolarge' && `${state.name} is too large.`}
                {state.kind === 'ready' && 'Photo ready to upload.'}
            </p>
            <div id={statusId}>
                {state.kind === 'working' && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner /> Preparing your photo…
                    </p>
                )}
                {state.kind === 'toolarge' && (
                    <p className="text-sm text-danger-text">
                        {state.name} is {formatBytes(state.bytes)}, over the{' '}
                        {maxUploadMb} MB limit, and could not be made smaller
                        here. Choose a smaller photo.
                    </p>
                )}
                {state.kind === 'refused' && (
                    <p className="text-sm text-danger-text">
                        {state.name} is not a JPEG, PNG or WebP photo. Choose
                        another file. If it is a HEIC photo from an iPhone,
                        change the camera setting to Most Compatible, or share
                        it as a JPEG.
                    </p>
                )}
                {state.kind === 'ready' && (
                    <div className="flex items-start gap-3 rounded-md border bg-muted/40 p-2">
                        {state.previewUrl && (
                            <img
                                src={state.previewUrl}
                                alt={`Preview of the ${label.toLowerCase()} photo`}
                                className="size-20 shrink-0 rounded-sm bg-background object-cover"
                            />
                        )}
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
                                    ? `Made smaller: ${formatBytes(state.result.original.bytes)} to ${formatBytes(state.result.output.bytes)}, ready to upload.`
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
                                    Choose another photo
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </Field>
    );
}
