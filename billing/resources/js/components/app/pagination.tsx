import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useQueryParams } from '@/components/app/use-query-params';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated, PaginatorMeta } from '@/types/app';

type PaginationProps = {
    paginator: Paginated<unknown>;
    /** Query-string key Laravel uses for the page (`->paginate(pageName: …)`). */
    pageName?: string;
    /** Partial reload: only refetch these props. */
    only?: string[];
    /** Noun for the summary, e.g. "invoices". */
    itemLabel?: string;
    className?: string;
};

function getMeta(paginator: Paginated<unknown>): PaginatorMeta {
    return 'meta' in paginator ? paginator.meta : paginator;
}

type PageLinkProps = {
    href: string | null;
    active?: boolean;
    only?: string[];
    label: string;
    children: ReactNode;
    className?: string;
};

function PageLink({
    href,
    active = false,
    only,
    label,
    children,
    className,
}: PageLinkProps) {
    const classes = cn(
        buttonVariants({ variant: active ? 'outline' : 'ghost', size: 'sm' }),
        'min-w-8 px-2 tabular-nums',
        className,
    );

    if (!href) {
        return (
            <span
                aria-disabled="true"
                aria-label={label}
                className={cn(classes, 'pointer-events-none opacity-50')}
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            aria-label={label}
            aria-current={active ? 'page' : undefined}
            preserveScroll
            {...(only ? { only } : {})}
            className={classes}
        >
            {children}
        </Link>
    );
}

/**
 * Renders a Laravel paginator (plain or API Resource shape). Page links are
 * built from the *current* URL so active filters/sort are always preserved.
 */
export function Pagination({
    paginator,
    pageName = 'page',
    only,
    itemLabel = 'results',
    className,
}: PaginationProps) {
    const { hrefWith } = useQueryParams();
    const meta = getMeta(paginator);
    const { current_page: current, last_page: last, from, to, total } = meta;

    const pageHref = (page: number) =>
        hrefWith({ [pageName]: page === 1 ? null : page });

    // Laravel's links[] = [prev, ...numbers and "...", next].
    const numbered = meta.links.slice(1, -1);

    // The table's own empty state already says there is nothing; a second "No …" line under it only repeats it.
    if (total === 0) {
        return null;
    }

    return (
        <div
            data-slot="pagination"
            className={cn(
                'flex flex-col items-center justify-between gap-3 text-sm sm:flex-row',
                className,
            )}
        >
            <p className="text-muted-foreground tabular-nums">
                Showing{' '}
                <span className="font-medium text-foreground">
                    {from}–{to}
                </span>{' '}
                of{' '}
                <span className="font-medium text-foreground">
                    {total.toLocaleString()}
                </span>{' '}
                {itemLabel}
            </p>

            {last > 1 && (
                <nav
                    aria-label="Pagination"
                    className="flex items-center gap-1"
                >
                    <PageLink
                        href={current > 1 ? pageHref(current - 1) : null}
                        label="Previous page"
                        only={only}
                    >
                        <ChevronLeft />
                        <span className="hidden sm:inline">Previous</span>
                    </PageLink>

                    <span className="px-2 text-muted-foreground tabular-nums sm:hidden">
                        Page {current} of {last}
                    </span>

                    {numbered.map((link, index) => {
                        const page = Number(link.label);

                        if (!Number.isInteger(page)) {
                            return (
                                <span
                                    key={`gap-${index}`}
                                    aria-hidden="true"
                                    className="hidden px-1 text-muted-foreground sm:inline"
                                >
                                    …
                                </span>
                            );
                        }

                        return (
                            <PageLink
                                key={page}
                                href={pageHref(page)}
                                active={page === current}
                                label={`Page ${page}`}
                                only={only}
                                className="hidden sm:inline-flex"
                            >
                                {page}
                            </PageLink>
                        );
                    })}

                    <PageLink
                        href={current < last ? pageHref(current + 1) : null}
                        label="Next page"
                        only={only}
                    >
                        <span className="hidden sm:inline">Next</span>
                        <ChevronRight />
                    </PageLink>
                </nav>
            )}
        </div>
    );
}
