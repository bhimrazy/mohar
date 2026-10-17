import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/app/status-badge';
import { cn } from '@/lib/utils';
import type { Href } from '@/types/app';

type PageHeaderProps = {
    title: ReactNode;
    description?: ReactNode;
    /** Status string rendered as a <StatusBadge>. */
    status?: string;
    /** Extra inline badges/meta next to the title. */
    badges?: ReactNode;
    /** Secondary line under the title, e.g. "Client #1042 · Created Mar 4". */
    meta?: ReactNode;
    /** Buttons. Put the primary action last (right-most). */
    actions?: ReactNode;
    back?: { href: Href; label: string };
    className?: string;
};

export function PageHeader({
    title,
    description,
    status,
    badges,
    meta,
    actions,
    back,
    className,
}: PageHeaderProps) {
    return (
        <header
            data-slot="page-header"
            className={cn('flex flex-col gap-3', className)}
        >
            {back && (
                <Link
                    href={back.href}
                    className="-ml-1 inline-flex w-fit items-center gap-1 rounded-md px-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <ChevronLeft className="size-4" aria-hidden="true" />
                    {back.label}
                </Link>
            )}

            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 className="min-w-0 truncate text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        {status && <StatusBadge status={status} />}
                        {badges}
                    </div>
                    {meta && (
                        <div className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
                            {meta}
                        </div>
                    )}
                    {description && (
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>

                {actions && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
        </header>
    );
}
