import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Copy,
    Inbox,
    Merge,
    MoreHorizontal,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Admin/TicketController';
import TicketMessageController from '@/actions/App/Modules/Support/Http/Controllers/Admin/TicketMessageController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Field } from '@/components/app/field';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { PriorityBadge } from '@/components/support/priority-badge';
import { ReplyForm } from '@/components/support/reply-form';
import { ServicePicker } from '@/components/support/service-picker';
import type { CannedReplyOption } from '@/components/support/reply-form';
import { TicketThread } from '@/components/support/ticket-thread';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { show as clientShow } from '@/routes/admin/clients';
import type { SelectOption } from '@/types/app';
import type {
    ServiceOption,
    TicketDetail,
    TicketMessage,
    UploadLimits,
} from '@/types/support';

type Props = {
    services: ServiceOption[];
    ticket: TicketDetail & { id: number; version: number };
    client: {
        id: number;
        display_name: string;
        email: string;
        status: string;
    } | null;
    messages: TicketMessage[];
    events: { id: number; description: string; created_at: string | null }[];
    blocked: {
        id: number;
        from: string;
        subject: string | null;
        received_at: string;
    }[];
    mergeCandidates: {
        id: number;
        number: string;
        subject: string;
        status: string;
    }[];
    departments: SelectOption[];
    staff: SelectOption[];
    statuses: SelectOption[];
    priorities: SelectOption[];
    cannedReplies: CannedReplyOption[];
    limits: UploadLimits;
    can: {
        reply: boolean;
        manage: boolean;
        delete: boolean;
        editMessages: boolean;
    };
};

export default function TicketShow(props: Props) {
    const { ticket, client, messages, events, blocked, can } = props;
    const [mergeOpen, setMergeOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [editing, setEditing] = useState<TicketMessage | null>(null);
    const [, copy] = useClipboard();
    const isClosed = ticket.status === 'closed';

    return (
        <>
            <Head title={`#${ticket.number} ${ticket.subject}`} />
            <Page>
                <PageHeader
                    back={{ href: TicketController.index(), label: 'Tickets' }}
                    title={ticket.subject}
                    status={ticket.status}
                    badges={
                        <>
                            <PriorityBadge priority={ticket.priority} />
                            {ticket.is_guest && (
                                <StatusBadge
                                    status="guest"
                                    label="Guest"
                                    tone="outline"
                                />
                            )}
                        </>
                    }
                    meta={
                        <>
                            <span className="font-mono">#{ticket.number}</span>
                            <span aria-hidden="true">·</span>
                            <span>{ticket.department}</span>
                            <span aria-hidden="true">·</span>
                            <span>
                                Opened{' '}
                                <DateTime value={ticket.created_at} relative />
                            </span>
                        </>
                    }
                    actions={
                        <>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {ticket.guest_url && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                void copy(
                                                    ticket.guest_url ?? '',
                                                )
                                            }
                                        >
                                            <Copy />
                                            Copy customer link
                                        </DropdownMenuItem>
                                    )}
                                    {can.manage &&
                                        props.mergeCandidates.length > 0 && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setMergeOpen(true)
                                                }
                                            >
                                                <Merge />
                                                Merge tickets…
                                            </DropdownMenuItem>
                                        )}
                                    {can.delete && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    setDeleteOpen(true)
                                                }
                                            >
                                                <Trash2 />
                                                Delete ticket
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                            {can.manage && !isClosed && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.patch(
                                            TicketController.update.url(
                                                ticket.id,
                                            ),
                                            { status: 'closed' },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Close ticket
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        {blocked.length > 0 && (
                            <Alert>
                                <Inbox />
                                <AlertTitle>
                                    {blocked.length === 1
                                        ? '1 email reply was held back'
                                        : `${blocked.length} email replies were held back`}
                                </AlertTitle>
                                <AlertDescription>
                                    <p>
                                        The sender is not linked to this ticket.
                                        Import a reply only if you trust it.
                                    </p>
                                    <ul className="mt-2 grid w-full gap-2">
                                        {blocked.map((email) => (
                                            <li
                                                key={email.id}
                                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-background px-3 py-2"
                                            >
                                                <span className="min-w-0 truncate text-sm">
                                                    <span className="font-medium text-foreground">
                                                        {email.from}
                                                    </span>{' '}
                                                    ·{' '}
                                                    <DateTime
                                                        value={
                                                            email.received_at
                                                        }
                                                        relative
                                                    />
                                                </span>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            TicketMessageController.import.url(
                                                                {
                                                                    ticket: ticket.id,
                                                                    inboundEmail:
                                                                        email.id,
                                                                },
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Import reply
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        )}

                        <TicketThread
                            messages={messages}
                            audience="admin"
                            renderActions={
                                can.editMessages
                                    ? (message) =>
                                          message.author_type !== 'system' && (
                                              <Button
                                                  variant="ghost"
                                                  size="icon"
                                                  className="size-7"
                                                  aria-label="Edit message"
                                                  onClick={() =>
                                                      setEditing(message)
                                                  }
                                              >
                                                  <Pencil className="size-3.5" />
                                              </Button>
                                          )
                                    : undefined
                            }
                        />

                        {can.reply && (
                            <SectionCard>
                                <Tabs defaultValue="reply">
                                    <TabsList>
                                        <TabsTrigger value="reply">
                                            Reply
                                        </TabsTrigger>
                                        <TabsTrigger value="note">
                                            Internal note
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent value="reply" className="pt-4">
                                        <ReplyForm
                                            idPrefix="reply"
                                            form={TicketMessageController.reply.form(
                                                ticket.id,
                                            )}
                                            limits={props.limits}
                                            hidden={{ version: ticket.version }}
                                            cannedReplies={props.cannedReplies}
                                            label="Reply to customer"
                                            submitLabel="Send reply"
                                            extra={
                                                <Select
                                                    name="status"
                                                    defaultValue="answered"
                                                >
                                                    <SelectTrigger
                                                        className="w-44"
                                                        aria-label="Status after reply"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {props.statuses.map(
                                                            (status) => (
                                                                <SelectItem
                                                                    key={
                                                                        status.value
                                                                    }
                                                                    value={
                                                                        status.value
                                                                    }
                                                                >
                                                                    Set to{' '}
                                                                    {status.label.toLowerCase()}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            }
                                        />
                                    </TabsContent>
                                    <TabsContent value="note" className="pt-4">
                                        <ReplyForm
                                            idPrefix="note"
                                            form={TicketMessageController.note.form(
                                                ticket.id,
                                            )}
                                            limits={props.limits}
                                            label="Internal note"
                                            placeholder="Only staff can see notes. Status does not change."
                                            submitLabel="Add note"
                                        />
                                    </TabsContent>
                                </Tabs>
                            </SectionCard>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        {can.manage && <OptionsCard {...props} />}

                        <SectionCard title="Requester">
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Name',
                                        value: ticket.requester_name,
                                    },
                                    {
                                        label: 'Email',
                                        value: ticket.requester_email,
                                    },
                                    {
                                        label: 'Client',
                                        value: client ? (
                                            <Link
                                                href={clientShow(client.id)}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {client.display_name}
                                            </Link>
                                        ) : (
                                            'Guest — not linked to a client'
                                        ),
                                    },
                                    {
                                        label: 'Related service',
                                        value: ticket.service_id
                                            ? (props.services.find(
                                                  (service) =>
                                                      service.id ===
                                                      ticket.service_id,
                                              )?.label ??
                                              `Service #${ticket.service_id}`)
                                            : null,
                                    },
                                    {
                                        label: 'First response',
                                        value: ticket.first_staff_reply_at ? (
                                            <DateTime
                                                value={
                                                    ticket.first_staff_reply_at
                                                }
                                                format="datetime"
                                            />
                                        ) : null,
                                    },
                                ]}
                            />
                        </SectionCard>

                        <SectionCard title="Activity">
                            <ol className="grid gap-3 text-sm">
                                {events.map((event) => (
                                    <li key={event.id} className="grid gap-0.5">
                                        <span>{event.description}</span>
                                        <DateTime
                                            value={event.created_at}
                                            relative
                                            className="text-xs text-muted-foreground"
                                        />
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>
                </div>
            </Page>

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title={`Delete ticket #${ticket.number}?`}
                description="The ticket, its messages and attachments are removed permanently. This can't be undone."
                confirmLabel="Delete ticket"
                action={TicketController.destroy(ticket.id)}
            />

            <MergeDialog
                open={mergeOpen}
                onOpenChange={setMergeOpen}
                ticket={ticket}
                candidates={props.mergeCandidates}
            />

            <EditMessageDialog
                ticketId={ticket.id}
                message={editing}
                onClose={() => setEditing(null)}
                canDelete={messages[0]?.id !== editing?.id}
            />
        </>
    );
}

function OptionsCard({
    ticket,
    statuses,
    priorities,
    departments,
    staff,
    services,
}: Props) {
    const [pinned, setPinned] = useState(Boolean(ticket.is_pinned));
    const [preventClose, setPreventClose] = useState(
        Boolean(ticket.prevent_client_close),
    );

    return (
        <Form
            {...TicketController.update.form(ticket.id)}
            options={{ preserveScroll: true }}
            key={ticket.version}
        >
            {({ errors, processing }) => (
                <SectionCard
                    title="Options"
                    footer={
                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <Spinner />}
                            Save options
                        </Button>
                    }
                >
                    <input
                        type="hidden"
                        name="version"
                        value={ticket.version}
                    />
                    <input
                        type="hidden"
                        name="is_pinned"
                        value={pinned ? '1' : '0'}
                    />
                    <input
                        type="hidden"
                        name="prevent_client_close"
                        value={preventClose ? '1' : '0'}
                    />
                    <div className="grid gap-4">
                        {errors.version && (
                            <p className="text-sm text-destructive">
                                {errors.version}
                            </p>
                        )}
                        <OptionSelect
                            name="status"
                            label="Status"
                            value={ticket.status}
                            options={statuses}
                            error={errors.status}
                        />
                        <OptionSelect
                            name="priority"
                            label="Priority"
                            value={ticket.priority}
                            options={priorities}
                            error={errors.priority}
                        />
                        <OptionSelect
                            name="department_id"
                            label="Department"
                            value={String(ticket.department_id)}
                            options={departments}
                            error={errors.department_id}
                        />
                        <OptionSelect
                            name="assigned_to"
                            label="Assignee"
                            value={
                                ticket.assigned_to
                                    ? String(ticket.assigned_to)
                                    : 'none'
                            }
                            options={[
                                { value: 'none', label: 'Unassigned' },
                                ...staff,
                            ]}
                            error={errors.assigned_to}
                        />
                        <Field
                            htmlFor="tags"
                            label="Tags"
                            optional
                            hint="Comma-separated"
                            error={errors.tags}
                        >
                            <Input
                                id="tags"
                                name="tags"
                                defaultValue={(ticket.tags ?? []).join(', ')}
                            />
                        </Field>
                        <Field
                            htmlFor="cc"
                            label="CC recipients"
                            optional
                            hint="Comma-separated emails, up to 10"
                            error={
                                errors.cc ??
                                Object.entries(errors).find(([key]) =>
                                    key.startsWith('cc.'),
                                )?.[1]
                            }
                        >
                            <Input
                                id="cc"
                                name="cc"
                                defaultValue={(ticket.cc ?? []).join(', ')}
                            />
                        </Field>
                        <ServicePicker
                            services={services}
                            defaultValue={ticket.service_id}
                            error={errors.service_id}
                        />
                        <ToggleRow
                            id="pinned"
                            label="Pin to top"
                            description="Pinned tickets list first for everyone."
                            checked={pinned}
                            onChange={setPinned}
                        />
                        <ToggleRow
                            id="prevent-close"
                            label="Prevent client close"
                            description="Only staff can close this ticket."
                            checked={preventClose}
                            onChange={setPreventClose}
                        />
                    </div>
                </SectionCard>
            )}
        </Form>
    );
}

function OptionSelect({
    name,
    label,
    value,
    options,
    error,
}: {
    name: string;
    label: string;
    value: string;
    options: SelectOption[];
    error?: string;
}) {
    const id = `option-${name}`;

    return (
        <Field htmlFor={id} label={label} error={error}>
            <Select name={name} defaultValue={value}>
                <SelectTrigger
                    id={id}
                    className="w-full"
                    aria-invalid={!!error}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

function ToggleRow({
    id,
    label,
    description,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
            <div className="space-y-0.5">
                <label htmlFor={id} className="text-sm font-medium">
                    {label}
                </label>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

function MergeDialog({
    open,
    onOpenChange,
    ticket,
    candidates,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    ticket: Props['ticket'];
    candidates: Props['mergeCandidates'];
}) {
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const merge = () =>
        router.post(
            TicketController.merge.url(),
            { tickets: [ticket.id, ...selected] },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Merge failed.'),
                onSuccess: () => onOpenChange(false),
            },
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Merge tickets into the oldest?</DialogTitle>
                    <DialogDescription>
                        Messages move to the oldest selected ticket in date
                        order. Merged tickets redirect there and email replies
                        follow.
                    </DialogDescription>
                </DialogHeader>
                <ul className="grid max-h-72 gap-2 overflow-y-auto">
                    {candidates.map((candidate) => (
                        <li
                            key={candidate.id}
                            className="flex items-center gap-3 rounded-lg border px-3 py-2"
                        >
                            <Checkbox
                                id={`merge-${candidate.id}`}
                                checked={selected.includes(candidate.id)}
                                onCheckedChange={(checked) =>
                                    setSelected((current) =>
                                        checked
                                            ? [...current, candidate.id]
                                            : current.filter(
                                                  (id) => id !== candidate.id,
                                              ),
                                    )
                                }
                            />
                            <Label
                                htmlFor={`merge-${candidate.id}`}
                                className="flex min-w-0 flex-1 items-center gap-2 font-normal"
                            >
                                <span className="font-mono text-xs text-muted-foreground">
                                    #{candidate.number}
                                </span>
                                <span className="truncate">
                                    {candidate.subject}
                                </span>
                            </Label>
                            <StatusBadge status={candidate.status} />
                        </li>
                    ))}
                </ul>
                {error && <p className="text-sm text-destructive">{error}</p>}
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={merge}
                        disabled={selected.length === 0 || processing}
                    >
                        {processing && <Spinner />}
                        Merge tickets
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function EditMessageDialog({
    ticketId,
    message,
    onClose,
    canDelete,
}: {
    ticketId: number;
    message: TicketMessage | null;
    onClose: () => void;
    canDelete: boolean;
}) {
    return (
        <Dialog
            open={message !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-xl">
                {message && (
                    <Form
                        {...TicketMessageController.update.form({
                            ticket: ticketId,
                            message: message.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                        className="grid gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Edit message</DialogTitle>
                                    <DialogDescription>
                                        The previous text is kept in the
                                        activity log.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-body">Message</Label>
                                    <Textarea
                                        id="edit-body"
                                        name="body"
                                        rows={8}
                                        defaultValue={message.body ?? ''}
                                    />
                                    {errors.body && (
                                        <p className="text-sm text-destructive">
                                            {errors.body}
                                        </p>
                                    )}
                                    {errors.message && (
                                        <p className="text-sm text-destructive">
                                            {errors.message}
                                        </p>
                                    )}
                                </div>
                                <DialogFooter className="sm:justify-between">
                                    {canDelete ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-destructive"
                                            onClick={() =>
                                                router.delete(
                                                    TicketMessageController.destroy.url(
                                                        {
                                                            ticket: ticketId,
                                                            message: message.id,
                                                        },
                                                    ),
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: onClose,
                                                    },
                                                )
                                            }
                                        >
                                            <Trash2 />
                                            Delete message
                                        </Button>
                                    ) : (
                                        <span />
                                    )}
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Save message
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
