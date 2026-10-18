import { Lock, Star } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateTime } from '@/components/app/date';
import { StatusBadge, toneSurface } from '@/components/app/status-badge';
import { AttachmentList } from '@/components/support/attachment-list';
import { Markdown } from '@/components/support/markdown';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { TicketMessage } from '@/types/support';

type TicketThreadProps = {
    messages: TicketMessage[];
    /** Staff see notes and sender details; customers see "Support team". */
    audience: 'admin' | 'customer';
    /** Extra controls rendered in a message header (edit/delete). */
    renderActions?: (message: TicketMessage) => ReactNode;
    /** Customer rating control for staff replies. */
    onRate?: (message: TicketMessage, rating: number) => void;
};

function authorLabel(message: TicketMessage) {
    if (message.author_type === 'system') {
        return message.is_autoresponse ? 'Automatic reply' : 'System';
    }

    return message.author_name;
}

/** Conversation thread: customer, staff, internal note and system styling. */
export function TicketThread({
    messages,
    audience,
    renderActions,
    onRate,
}: TicketThreadProps) {
    const getInitials = useInitials();

    return (
        <ol className="flex flex-col gap-4" aria-label="Conversation">
            {messages.map((message) => {
                const isNote = message.type === 'note';
                const isStaff = message.author_type === 'staff';
                const isSystem = message.author_type === 'system';

                return (
                    <li
                        key={message.id}
                        id={`message-${message.id}`}
                        aria-label={`${isNote ? 'Internal note' : 'Message'} from ${authorLabel(message)}`}
                        className={cn(
                            'rounded-xl border shadow-xs',
                            isNote &&
                                cn(
                                    'border-dashed',
                                    toneSurface('warning'),
                                    'text-foreground dark:text-foreground',
                                ),
                            !isNote &&
                                isStaff &&
                                cn(
                                    toneSurface('info'),
                                    'text-foreground dark:text-foreground',
                                ),
                            !isNote && !isStaff && !isSystem && 'bg-card',
                            isSystem && !isNote && 'bg-background',
                            isStaff && !isNote && 'sm:ml-8',
                            !isStaff && !isSystem && !isNote && 'sm:mr-8',
                        )}
                    >
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-2.5">
                            <Avatar className="size-7">
                                <AvatarFallback className="text-xs">
                                    {isNote ? (
                                        <Lock
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    ) : (
                                        getInitials(message.author_name)
                                    )}
                                </AvatarFallback>
                            </Avatar>
                            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="truncate text-sm font-medium">
                                    {authorLabel(message)}
                                </span>
                                {isNote && (
                                    <StatusBadge
                                        status="internal-note"
                                        label="Internal note"
                                        tone="warning"
                                    />
                                )}
                                {audience === 'customer' && isStaff && (
                                    <StatusBadge
                                        status="staff"
                                        label="Support team"
                                        tone="info"
                                    />
                                )}
                                {audience === 'admin' &&
                                    !isNote &&
                                    !isSystem && (
                                        <StatusBadge
                                            status={message.author_type}
                                            label={
                                                isStaff
                                                    ? 'Staff'
                                                    : message.author_type ===
                                                        'guest'
                                                      ? 'Guest'
                                                      : 'Client'
                                            }
                                            tone={isStaff ? 'info' : 'neutral'}
                                        />
                                    )}
                                {message.is_unverified_sender && (
                                    <StatusBadge
                                        status="unverified"
                                        label="Unverified sender"
                                        tone="danger"
                                    />
                                )}
                                {audience === 'admin' &&
                                    message.author_email &&
                                    !isStaff && (
                                        <span className="truncate text-xs text-muted-foreground">
                                            {message.author_email}
                                        </span>
                                    )}
                                {audience === 'admin' &&
                                    message.source === 'email' && (
                                        <span className="text-xs text-muted-foreground">
                                            via email
                                        </span>
                                    )}
                            </div>
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                {message.edited_at && <span>edited</span>}
                                <DateTime value={message.created_at} relative />
                                {renderActions?.(message)}
                            </div>
                        </div>
                        <div className="space-y-3 px-4 py-3">
                            <Markdown html={message.body_html} />
                            <AttachmentList attachments={message.attachments} />
                            {(message.can_rate ||
                                (audience === 'admin' &&
                                    message.rating !== null)) && (
                                <ReplyRating
                                    rating={message.rating}
                                    readOnly={!message.can_rate || !onRate}
                                    onRate={(rating) =>
                                        onRate?.(message, rating)
                                    }
                                />
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function ReplyRating({
    rating,
    readOnly,
    onRate,
}: {
    rating: number | null;
    readOnly: boolean;
    onRate: (rating: number) => void;
}) {
    return (
        <div className="flex items-center gap-2 border-t pt-3 text-xs text-muted-foreground">
            <span>{readOnly ? 'Rating' : 'Was this reply helpful?'}</span>
            <div
                className="flex items-center"
                role={readOnly ? 'img' : 'group'}
                aria-label={
                    rating ? `Rated ${rating} out of 5` : 'Rate this reply'
                }
            >
                {[1, 2, 3, 4, 5].map((value) => {
                    const filled = rating !== null && value <= rating;
                    const star = (
                        <Star
                            className={cn(
                                'size-4',
                                filled
                                    ? 'fill-foreground text-foreground'
                                    : 'text-muted-foreground',
                            )}
                            aria-hidden
                        />
                    );

                    return readOnly ? (
                        <span key={value} className="p-0.5">
                            {star}
                        </span>
                    ) : (
                        <button
                            key={value}
                            type="button"
                            onClick={() => onRate(value)}
                            className="rounded p-0.5 hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            aria-label={`Rate ${value} out of 5`}
                            aria-pressed={rating === value}
                        >
                            {star}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
