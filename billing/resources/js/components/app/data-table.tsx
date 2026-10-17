import { Link, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { Href } from '@/types/app';

export type DataTableColumn<T> = {
    /** Stable key; also used as React key. */
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    align?: 'left' | 'center' | 'right';
    /** Applied to both <th> and <td>. Use for widths, e.g. "w-32". */
    className?: string;
    /** Used as the card title on mobile and wrapped in the row link. */
    primary?: boolean;
    /** Leave out of the stacked mobile card (secondary data, actions). */
    hideOnMobile?: boolean;
};

type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    getRowKey: (row: T) => string | number;
    /** Makes each row navigate (whole row is clickable, link is focusable). */
    rowHref?: (row: T) => Href | undefined;
    /** Shown in place of the table body when `rows` is empty. */
    empty?: ReactNode;
    /** Rendered under the table inside the card, e.g. <Pagination>. */
    footer?: ReactNode;
    /** Dims the table while a filter/page request is in flight. */
    processing?: boolean;
    /** Accessible table name (visually hidden). */
    caption?: string;
    className?: string;
};

const alignClasses = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
} as const;

const INTERACTIVE =
    'a, button, input, select, textarea, label, [role="menuitem"]';

function isInteractiveClick(event: MouseEvent<HTMLElement>): boolean {
    const target = event.target as HTMLElement;

    return (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        target.closest(INTERACTIVE) !== null
    );
}

export function DataTable<T>({
    columns,
    rows,
    getRowKey,
    rowHref,
    empty,
    footer,
    processing = false,
    caption,
    className,
}: DataTableProps<T>) {
    const primary = columns.find((column) => column.primary) ?? columns[0];
    const mobileColumns = columns.filter(
        (column) => column !== primary && !column.hideOnMobile,
    );
    const isEmpty = rows.length === 0;

    const renderPrimary = (row: T, href: Href | undefined) =>
        href ? (
            <Link
                href={href}
                className="font-medium text-foreground hover:underline focus-visible:underline focus-visible:outline-none"
            >
                {primary.cell(row)}
            </Link>
        ) : (
            primary.cell(row)
        );

    return (
        <div
            data-slot="data-table"
            aria-busy={processing || undefined}
            className={cn(
                'overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card transition-opacity',
                processing && 'opacity-60',
                className,
            )}
        >
            {isEmpty ? (
                empty
            ) : (
                <>
                    {/* Desktop / tablet: real table */}
                    <Table className="hidden md:table">
                        {caption && (
                            <caption className="sr-only">{caption}</caption>
                        )}
                        <TableHeader className="bg-muted/40">
                            <TableRow className="hover:bg-transparent">
                                {columns.map((column) => (
                                    <TableHead
                                        key={column.key}
                                        scope="col"
                                        className={cn(
                                            'h-(--table-head-h) px-3 eyebrow text-muted-foreground first:pl-4 last:pr-4',
                                            alignClasses[
                                                column.align ?? 'left'
                                            ],
                                            column.className,
                                        )}
                                    >
                                        {column.header}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => {
                                const href = rowHref?.(row);

                                return (
                                    <TableRow
                                        key={getRowKey(row)}
                                        className={cn(href && 'cursor-pointer')}
                                        onClick={
                                            href
                                                ? (event) => {
                                                      if (
                                                          !isInteractiveClick(
                                                              event,
                                                          )
                                                      ) {
                                                          router.visit(href);
                                                      }
                                                  }
                                                : undefined
                                        }
                                    >
                                        {columns.map((column) => (
                                            <TableCell
                                                key={column.key}
                                                className={cn(
                                                    'px-3 py-(--cell-py) first:pl-4 last:pr-4',
                                                    alignClasses[
                                                        column.align ?? 'left'
                                                    ],
                                                    column.className,
                                                )}
                                            >
                                                {column === primary
                                                    ? renderPrimary(row, href)
                                                    : column.cell(row)}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>

                    {/* Mobile: stacked cards */}
                    <ul className="divide-y md:hidden">
                        {rows.map((row) => {
                            const href = rowHref?.(row);
                            const content = (
                                <>
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="min-w-0 truncate font-medium">
                                            {primary.cell(row)}
                                        </div>
                                        {href && (
                                            <ChevronRight
                                                className="size-4 shrink-0 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </div>
                                    {mobileColumns.length > 0 && (
                                        <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                            {mobileColumns.map((column) => (
                                                <div
                                                    key={column.key}
                                                    className="min-w-0"
                                                >
                                                    <dt className="text-xs text-muted-foreground">
                                                        {column.header}
                                                    </dt>
                                                    <dd className="truncate">
                                                        {column.cell(row)}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    )}
                                </>
                            );

                            return (
                                <li key={getRowKey(row)}>
                                    {href ? (
                                        <Link
                                            href={href}
                                            className="block px-4 py-3 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                                        >
                                            {content}
                                        </Link>
                                    ) : (
                                        <div className="px-4 py-3">
                                            {content}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}

            {footer && <div className="border-t px-4 py-3">{footer}</div>}
        </div>
    );
}

type DataTableSkeletonProps = {
    columns?: number;
    rows?: number;
    className?: string;
};

/** Placeholder for deferred table props. */
export function DataTableSkeleton({
    columns = 5,
    rows = 6,
    className,
}: DataTableSkeletonProps) {
    return (
        <div
            data-slot="data-table-skeleton"
            aria-busy="true"
            aria-label="Loading"
            className={cn(
                'overflow-hidden rounded-xl border bg-card elevation-card',
                className,
            )}
        >
            <div className="h-9 border-b bg-muted/40" />
            <div className="divide-y">
                {Array.from({ length: rows }, (_, rowIndex) => (
                    <div key={rowIndex} className="flex gap-6 px-4 py-3.5">
                        {Array.from({ length: columns }, (_, columnIndex) => (
                            <Skeleton
                                key={columnIndex}
                                className={cn(
                                    'h-4 flex-1',
                                    columnIndex === 0 && 'max-w-48',
                                )}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
