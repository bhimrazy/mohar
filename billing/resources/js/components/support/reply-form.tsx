import { Form } from '@inertiajs/react';
import { AlertTriangle, MessageSquareText, Paperclip } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { RouteFormDefinition } from '@/wayfinder';
import type { UploadLimits } from '@/types/support';

export type CannedReplyOption = {
    id: number;
    name: string;
    body: string;
    category: string | null;
};

type ReplyFormProps = {
    form: RouteFormDefinition<'post'>;
    limits: UploadLimits;
    submitLabel: string;
    label?: string;
    placeholder?: string;
    /** Hidden fields sent with the reply, e.g. the ticket version. */
    hidden?: Record<string, string | number>;
    /** Extra controls next to the submit button (e.g. status select). */
    extra?: ReactNode;
    cannedReplies?: CannedReplyOption[];
    /** Unique prefix for element ids when several forms share a page. */
    idPrefix?: string;
};

/** Markdown reply box with attachments, optional canned replies and collision handling. */
export function ReplyForm({
    form,
    limits,
    submitLabel,
    label = 'Your reply',
    placeholder = 'Write your message… Markdown is supported.',
    hidden = {},
    extra,
    cannedReplies,
    idPrefix = 'reply',
}: ReplyFormProps) {
    const [body, setBody] = useState('');
    const forceRef = useRef<HTMLInputElement>(null);
    const bodyId = `${idPrefix}-body`;
    const filesId = `${idPrefix}-attachments`;

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess={['attachments[]']}
            onSuccess={() => {
                setBody('');

                if (forceRef.current) {
                    forceRef.current.value = '0';
                }
            }}
            className="grid gap-4"
        >
            {({ errors, processing, submit }) => {
                const attachmentErrors = Object.entries(errors)
                    .filter(([key]) => key.startsWith('attachments'))
                    .map(([, message]) => message);

                return (
                    <>
                        {Object.entries(hidden).map(([name, value]) => (
                            <input
                                key={name}
                                type="hidden"
                                name={name}
                                value={value}
                            />
                        ))}
                        <input
                            ref={forceRef}
                            type="hidden"
                            name="force"
                            defaultValue="0"
                        />

                        {errors.version && (
                            <Alert>
                                <AlertTriangle />
                                <AlertTitle>
                                    This ticket changed while you were writing
                                </AlertTitle>
                                <AlertDescription>
                                    <p>{errors.version}</p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                window.location.reload()
                                            }
                                        >
                                            Reload ticket
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => {
                                                if (forceRef.current) {
                                                    forceRef.current.value =
                                                        '1';
                                                }

                                                submit();
                                            }}
                                        >
                                            Post anyway
                                        </Button>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="grid gap-2">
                            <div className="flex items-center justify-between gap-2">
                                <Label htmlFor={bodyId}>{label}</Label>
                                {cannedReplies && cannedReplies.length > 0 && (
                                    <CannedReplyPicker
                                        replies={cannedReplies}
                                        onPick={(text) =>
                                            setBody((current) =>
                                                current.trim() === ''
                                                    ? text
                                                    : `${current.trimEnd()}\n\n${text}`,
                                            )
                                        }
                                    />
                                )}
                            </div>
                            <Textarea
                                id={bodyId}
                                name="body"
                                rows={6}
                                value={body}
                                onChange={(event) =>
                                    setBody(event.target.value)
                                }
                                placeholder={placeholder}
                                aria-invalid={!!errors.body}
                                aria-describedby={`${bodyId}-description`}
                                required
                            />
                            <InputError
                                id={`${bodyId}-description`}
                                message={errors.body}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label
                                htmlFor={filesId}
                                className="flex items-center gap-1.5 font-normal text-muted-foreground"
                            >
                                <Paperclip className="size-4" aria-hidden />
                                Attachments
                                <span className="text-xs">
                                    (up to {limits.max_files} files,{' '}
                                    {limits.max_mb} MB each)
                                </span>
                            </Label>
                            <Input
                                id={filesId}
                                type="file"
                                name="attachments[]"
                                multiple
                                accept={limits.extensions
                                    .map((extension) => `.${extension}`)
                                    .join(',')}
                                aria-describedby={`${filesId}-description`}
                                className="h-auto py-1.5 file:mr-3"
                            />
                            <p
                                id={`${filesId}-description`}
                                className="text-xs text-muted-foreground"
                            >
                                Allowed: {limits.extensions.join(', ')}
                            </p>
                            {attachmentErrors.map((message) => (
                                <InputError key={message} message={message} />
                            ))}
                        </div>

                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {extra}
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {submitLabel}
                            </Button>
                        </div>
                    </>
                );
            }}
        </Form>
    );
}

function CannedReplyPicker({
    replies,
    onPick,
}: {
    replies: CannedReplyOption[];
    onPick: (body: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    const matches = useMemo(() => {
        const term = search.trim().toLowerCase();

        return replies.filter(
            (reply) =>
                term === '' ||
                reply.name.toLowerCase().includes(term) ||
                reply.body.toLowerCase().includes(term),
        );
    }, [replies, search]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="sm">
                    <MessageSquareText />
                    Insert canned reply
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Insert canned reply</DialogTitle>
                    <DialogDescription>
                        Tokens like {'{{ requester.first_name }}'} are filled in
                        when the reply is sent.
                    </DialogDescription>
                </DialogHeader>
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search canned replies…"
                    aria-label="Search canned replies"
                />
                <ul className="-mx-2 max-h-80 overflow-y-auto">
                    {matches.length === 0 && (
                        <li className="px-2 py-6 text-center text-sm text-muted-foreground">
                            No canned replies match.
                        </li>
                    )}
                    {matches.map((reply) => (
                        <li key={reply.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onPick(reply.body);
                                    setOpen(false);
                                    setSearch('');
                                }}
                                className="w-full rounded-lg px-2 py-2 text-left transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                            >
                                <span className="block text-sm font-medium">
                                    {reply.name}
                                    {reply.category && (
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {reply.category}
                                        </span>
                                    )}
                                </span>
                                <span className="line-clamp-2 text-xs text-muted-foreground">
                                    {reply.body}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
