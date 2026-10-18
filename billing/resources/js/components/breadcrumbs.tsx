import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    // Phones show one compact link back to the nearest linked level instead of the trail.
    const back = breadcrumbs
        .slice(0, -1)
        .reverse()
        .find((item) => item.href !== undefined);

    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb>
                    <BreadcrumbList>
                        {back?.href !== undefined && (
                            <BreadcrumbItem className="sm:hidden">
                                <BreadcrumbLink asChild>
                                    <Link
                                        href={back.href}
                                        className="inline-flex items-center gap-1"
                                    >
                                        <ChevronLeft
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {back.title}
                                    </Link>
                                </BreadcrumbLink>
                            </BreadcrumbItem>
                        )}
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;

                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem
                                        className={
                                            isLast && !back
                                                ? 'min-w-0'
                                                : 'hidden min-w-0 sm:inline-flex'
                                        }
                                    >
                                        {isLast ? (
                                            <BreadcrumbPage className="max-w-[16rem] truncate sm:max-w-sm">
                                                {item.title}
                                            </BreadcrumbPage>
                                        ) : item.href === undefined ? (
                                            <span className="text-muted-foreground">
                                                {item.title}
                                            </span>
                                        ) : (
                                            <BreadcrumbLink asChild>
                                                <Link href={item.href}>
                                                    {item.title}
                                                </Link>
                                            </BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && (
                                        <BreadcrumbSeparator className="hidden sm:list-item" />
                                    )}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
