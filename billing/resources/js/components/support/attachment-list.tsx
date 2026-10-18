import { FileText, Paperclip } from 'lucide-react';
import { formatBytes } from '@/components/support/format';
import { cn } from '@/lib/utils';
import type { TicketAttachment } from '@/types/support';

/** Attachment chips; images get a small inline thumbnail. */
export function AttachmentList({
    attachments,
    className,
}: {
    attachments: TicketAttachment[];
    className?: string;
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul
            className={cn('flex flex-wrap gap-2', className)}
            aria-label="Attachments"
        >
            {attachments.map((attachment) => (
                <li key={attachment.id}>
                    {attachment.pruned ? (
                        <span className="inline-flex items-center gap-2 rounded-lg border border-dashed px-2.5 py-1.5 text-xs text-muted-foreground">
                            <Paperclip className="size-3.5" aria-hidden />
                            {attachment.name} · removed
                        </span>
                    ) : (
                        <a
                            href={attachment.url}
                            className="inline-flex max-w-64 items-center gap-2 rounded-lg border bg-background px-2.5 py-1.5 text-xs transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            {attachment.is_image ? (
                                <img
                                    src={`${attachment.url}${attachment.url.includes('?') ? '&' : '?'}inline=1`}
                                    alt=""
                                    className="size-6 rounded object-cover"
                                    loading="lazy"
                                />
                            ) : (
                                <FileText
                                    className="size-4 text-muted-foreground"
                                    aria-hidden
                                />
                            )}
                            <span className="truncate font-medium">
                                {attachment.name}
                            </span>
                            <span className="shrink-0 text-muted-foreground">
                                {formatBytes(attachment.size_bytes)}
                            </span>
                        </a>
                    )}
                </li>
            ))}
        </ul>
    );
}
