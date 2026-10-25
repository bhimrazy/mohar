import type { ReactNode } from 'react';
import { useInClientArea } from '@/lib/layout-area';
import { cn } from '@/lib/utils';

/**
 * Page intro band for public store pages. Inside the client area it reads as a page
 * header (no marketing eyebrow, client-area title size) so the store looks like the
 * rest of the account.
 */
export function StorefrontHero({
    eyebrow,
    title,
    description,
    children,
    className,
}: {
    eyebrow?: string;
    title: string;
    description?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    const inClientArea = useInClientArea();

    if (inClientArea) {
        return (
            <section className={cn('border-b bg-muted/30', className)}>
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-6 md:px-6 md:py-8">
                    <h1 className="font-display text-2xl text-balance">
                        {title}
                    </h1>
                    {description && (
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                    {children}
                </div>
            </section>
        );
    }

    return (
        <section className={cn('border-b bg-muted/30', className)}>
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-10 md:px-6 md:py-14">
                {eyebrow && (
                    <p className="text-sm font-medium text-muted-foreground">
                        {eyebrow}
                    </p>
                )}
                <h1 className="max-w-3xl text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-2xl text-base text-muted-foreground">
                        {description}
                    </p>
                )}
                {children}
            </div>
        </section>
    );
}

/** A store page's h1: the public display size, or the client area's page-title size. */
export function StoreTitle({ children }: { children: ReactNode }) {
    const inClientArea = useInClientArea();

    return (
        <h1
            className={
                inClientArea
                    ? 'font-display text-2xl'
                    : 'text-3xl font-semibold tracking-tight'
            }
        >
            {children}
        </h1>
    );
}
